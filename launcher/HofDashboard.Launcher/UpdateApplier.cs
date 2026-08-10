using System.Diagnostics;
using System.Text.Json;

namespace HofDashboard.Launcher;

internal static class UpdateApplier
{
    private const string ApplyFlag = "--apply-update";

    public static void Start(PreparedUpdate update, AppPaths paths, LauncherLog log)
    {
        var helperPath = Path.Combine(paths.UpdateHelperDirectory, "HofDashboard.UpdateHelper.exe");
        File.Copy(Application.ExecutablePath, helperPath, overwrite: true);

        var startInfo = new ProcessStartInfo
        {
            FileName = helperPath,
            WorkingDirectory = paths.UpdateHelperDirectory,
            UseShellExecute = false,
        };
        startInfo.ArgumentList.Add(ApplyFlag);
        startInfo.ArgumentList.Add("--parent-process-id");
        startInfo.ArgumentList.Add(Environment.ProcessId.ToString());
        startInfo.ArgumentList.Add("--staging");
        startInfo.ArgumentList.Add(update.StagingDirectory);
        startInfo.ArgumentList.Add("--target");
        startInfo.ArgumentList.Add(paths.InstallDirectory);

        if (Process.Start(startInfo) is null)
        {
            throw new InvalidOperationException("Der Update-Helfer konnte nicht gestartet werden.");
        }

        log.Info($"Update-Helfer für Version {update.Version} wurde gestartet.");
    }

    public static async Task<int> RunAsync(string[] args)
    {
        LauncherLog? log = null;
        try
        {
            var paths = AppPaths.Create();
            log = new LauncherLog(paths.LauncherLog);
            var options = ParseArguments(args);
            log.Info($"Update-Helfer startet für Zielordner {options.TargetDirectory}.");

            var newManifest = await PackageManifest.LoadAndValidateAsync(
                options.StagingDirectory,
                expectedVersion: null,
                CancellationToken.None);
            await WaitForParentAsync(options.ParentProcessId);
            ApplyUpdate(options, newManifest, paths.UpdateBackupDirectory, log);

            if (!options.NoRestart)
            {
                var executable = Path.Combine(options.TargetDirectory, "HofDashboard.exe");
                Process.Start(new ProcessStartInfo
                {
                    FileName = executable,
                    WorkingDirectory = options.TargetDirectory,
                    UseShellExecute = true,
                });
            }

            log.Info($"Update auf Version {newManifest.ApplicationVersion} erfolgreich abgeschlossen.");
            return 0;
        }
        catch (Exception exception)
        {
            log?.Error("Update-Helfer ist fehlgeschlagen.", exception);
            if (!args.Contains("--no-restart", StringComparer.OrdinalIgnoreCase))
            {
                MessageBox.Show(
                    $"Das Update konnte nicht installiert werden. Die vorherige App-Version wurde wiederhergestellt.\n\n{exception.Message}",
                    "Update fehlgeschlagen",
                    MessageBoxButtons.OK,
                    MessageBoxIcon.Error);
            }
            return 1;
        }
    }

    private static UpdateOptions ParseArguments(string[] args)
    {
        if (!args.Contains(ApplyFlag, StringComparer.OrdinalIgnoreCase))
        {
            throw new ArgumentException("Update-Modus fehlt.");
        }

        var parentIdText = ReadValue(args, "--parent-process-id");
        if (!int.TryParse(parentIdText, out var parentId) || parentId < 0)
        {
            throw new ArgumentException("Ungültige Prozess-ID für das Update.");
        }

        var staging = Path.GetFullPath(ReadValue(args, "--staging"));
        var target = Path.GetFullPath(ReadValue(args, "--target"));
        ValidateTargetDirectory(target);
        return new UpdateOptions(
            parentId,
            staging,
            target,
            args.Contains("--no-restart", StringComparer.OrdinalIgnoreCase));
    }

    private static string ReadValue(string[] args, string name)
    {
        var index = Array.FindIndex(args, value => value.Equals(name, StringComparison.OrdinalIgnoreCase));
        if (index < 0 || index + 1 >= args.Length || string.IsNullOrWhiteSpace(args[index + 1]))
        {
            throw new ArgumentException($"Fehlender Updateparameter: {name}");
        }

        return args[index + 1];
    }

    private static void ValidateTargetDirectory(string targetDirectory)
    {
        var root = Path.GetPathRoot(targetDirectory);
        if (string.IsNullOrWhiteSpace(root)
            || targetDirectory.TrimEnd(Path.DirectorySeparatorChar)
                .Equals(root.TrimEnd(Path.DirectorySeparatorChar), StringComparison.OrdinalIgnoreCase)
            || !Directory.Exists(targetDirectory))
        {
            throw new InvalidOperationException("Der Installationsordner ist kein sicheres Updateziel.");
        }

        var probe = Path.Combine(targetDirectory, $".hofdashboard-write-{Guid.NewGuid():N}.tmp");
        try
        {
            File.WriteAllText(probe, string.Empty);
        }
        finally
        {
            if (File.Exists(probe))
            {
                File.Delete(probe);
            }
        }
    }

    private static async Task WaitForParentAsync(int parentProcessId)
    {
        if (parentProcessId == 0)
        {
            return;
        }

        try
        {
            using var parent = Process.GetProcessById(parentProcessId);
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(90));
            await parent.WaitForExitAsync(timeout.Token);
        }
        catch (ArgumentException)
        {
            // The parent process already exited before the helper attached.
        }
    }

    private static void ApplyUpdate(
        UpdateOptions options,
        PackageManifest newManifest,
        string backupRoot,
        LauncherLog log)
    {
        var oldManifest = LoadExistingManifest(options.TargetDirectory);
        ResetBackupDirectory(backupRoot);

        var oldFiles = oldManifest?.Files.ToDictionary(file => file.Path, StringComparer.OrdinalIgnoreCase)
            ?? new Dictionary<string, PackageFile>(StringComparer.OrdinalIgnoreCase);
        var newFiles = newManifest.Files.ToDictionary(file => file.Path, StringComparer.OrdinalIgnoreCase);
        var createdFiles = new List<string>();

        try
        {
            BackupExistingFiles(options.TargetDirectory, backupRoot, oldFiles.Values);
            var existingManifestPath = Path.Combine(options.TargetDirectory, PackageManifest.FileName);
            if (File.Exists(existingManifestPath))
            {
                File.Copy(
                    existingManifestPath,
                    Path.Combine(backupRoot, PackageManifest.FileName),
                    overwrite: true);
            }

            foreach (var file in newManifest.Files)
            {
                var source = PackageManifest.ResolvePackagePath(options.StagingDirectory, file.Path);
                var target = PackageManifest.ResolvePackagePath(options.TargetDirectory, file.Path);
                Directory.CreateDirectory(Path.GetDirectoryName(target)!);
                if (!File.Exists(target))
                {
                    createdFiles.Add(file.Path);
                }

                var temporaryTarget = target + ".hofdashboard-update";
                File.Copy(source, temporaryTarget, overwrite: true);
                File.Move(temporaryTarget, target, overwrite: true);
            }

            foreach (var obsolete in oldFiles.Keys.Except(newFiles.Keys, StringComparer.OrdinalIgnoreCase))
            {
                var obsoletePath = PackageManifest.ResolvePackagePath(options.TargetDirectory, obsolete);
                if (File.Exists(obsoletePath))
                {
                    File.Delete(obsoletePath);
                }
            }

            File.Copy(
                Path.Combine(options.StagingDirectory, PackageManifest.FileName),
                Path.Combine(options.TargetDirectory, PackageManifest.FileName),
                overwrite: true);
        }
        catch
        {
            log.Warning("Update fehlgeschlagen; stelle die vorherigen Paketdateien wieder her.");
            foreach (var relativePath in createdFiles)
            {
                var createdPath = PackageManifest.ResolvePackagePath(options.TargetDirectory, relativePath);
                if (File.Exists(createdPath))
                {
                    File.Delete(createdPath);
                }
            }

            RestoreBackup(options.TargetDirectory, backupRoot, oldFiles.Values);
            var backupManifest = Path.Combine(backupRoot, PackageManifest.FileName);
            if (File.Exists(backupManifest))
            {
                File.Copy(
                    backupManifest,
                    Path.Combine(options.TargetDirectory, PackageManifest.FileName),
                    overwrite: true);
            }
            throw;
        }
    }

    private static PackageManifest? LoadExistingManifest(string targetDirectory)
    {
        var path = Path.Combine(targetDirectory, PackageManifest.FileName);
        if (!File.Exists(path))
        {
            return null;
        }

        return JsonSerializer.Deserialize<PackageManifest>(File.ReadAllText(path))
            ?? throw new InvalidDataException("Das vorhandene Paketdatei-Manifest ist leer.");
    }

    private static void BackupExistingFiles(
        string targetDirectory,
        string backupDirectory,
        IEnumerable<PackageFile> files)
    {
        foreach (var file in files)
        {
            var source = PackageManifest.ResolvePackagePath(targetDirectory, file.Path);
            if (!File.Exists(source))
            {
                continue;
            }

            var backup = PackageManifest.ResolvePackagePath(backupDirectory, file.Path);
            Directory.CreateDirectory(Path.GetDirectoryName(backup)!);
            File.Copy(source, backup, overwrite: true);
        }
    }

    private static void RestoreBackup(
        string targetDirectory,
        string backupDirectory,
        IEnumerable<PackageFile> files)
    {
        foreach (var file in files)
        {
            var backup = PackageManifest.ResolvePackagePath(backupDirectory, file.Path);
            if (!File.Exists(backup))
            {
                continue;
            }

            var target = PackageManifest.ResolvePackagePath(targetDirectory, file.Path);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(backup, target, overwrite: true);
        }
    }

    private static void ResetBackupDirectory(string backupDirectory)
    {
        if (Directory.Exists(backupDirectory))
        {
            Directory.Delete(backupDirectory, recursive: true);
        }
        Directory.CreateDirectory(backupDirectory);
    }

    private sealed record UpdateOptions(
        int ParentProcessId,
        string StagingDirectory,
        string TargetDirectory,
        bool NoRestart);
}

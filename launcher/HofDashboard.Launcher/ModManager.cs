using System.Diagnostics;
using System.IO.Compression;
using System.Xml.Linq;

namespace HofDashboard.Launcher;

internal sealed class ModManager
{
    private const string ModFileName = "FS25_HofDashboard.zip";
    private const string CustomPathFileName = "mod-directory.txt";

    private readonly AppPaths _paths;
    private readonly LauncherLog _log;
    private readonly SemaphoreSlim _operationGate = new(1, 1);

    public ModManager(AppPaths paths, LauncherLog log)
    {
        _paths = paths;
        _log = log;
    }

    public string GetPreferredModDirectory()
    {
        var customDirectory = ReadCustomDirectory();
        if (!string.IsNullOrWhiteSpace(customDirectory))
        {
            return customDirectory;
        }

        return GetAutomaticCandidates().FirstOrDefault()
            ?? Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.UserProfile),
                "Documents",
                "My Games",
                "FarmingSimulator2025",
                "mods");
    }

    public void SetCustomModDirectory(string directory)
    {
        var normalized = NormalizeSelectedDirectory(directory);
        Directory.CreateDirectory(_paths.UserDataRoot);
        File.WriteAllText(CustomPathFile, normalized);
        _log.Info($"Benutzerdefinierter LS25-Modordner gespeichert: {normalized}");
    }

    public async Task<ModManagerStatus> GetStatusAsync(CancellationToken cancellationToken)
    {
        var directory = GetPreferredModDirectory();
        var usesCustomDirectory = !string.IsNullOrWhiteSpace(ReadCustomDirectory());
        var installed = InspectInstalledMod(directory);
        var gameRunning = IsGameRunning();

        try
        {
            using var service = new UpdateService(_log);
            var release = await service.GetLatestAsync(cancellationToken);
            return BuildStatus(
                directory,
                installed,
                release.ModVersion,
                release.ModReleaseNotesUri,
                gameRunning,
                usesCustomDirectory,
                onlineAvailable: true);
        }
        catch (OperationCanceledException)
        {
            throw;
        }
        catch (Exception exception)
        {
            _log.Warning($"Mod-Status konnte online nicht vollständig geprüft werden: {exception.Message}");
            return BuildStatus(
                directory,
                installed,
                availableVersion: null,
                releaseNotesUri: null,
                gameRunning,
                usesCustomDirectory,
                onlineAvailable: false);
        }
    }

    public async Task<ModManagerStatus> InstallOrUpdateAsync(
        IProgress<ModInstallProgress>? progress,
        CancellationToken cancellationToken)
    {
        await _operationGate.WaitAsync(cancellationToken);
        try
        {
            if (IsGameRunning())
            {
                throw new InvalidOperationException(
                    "Landwirtschafts-Simulator 25 läuft gerade. Bitte beende das Spiel und starte die Installation danach erneut.");
            }

            var directory = GetPreferredModDirectory();
            if (string.IsNullOrWhiteSpace(directory))
            {
                throw new InvalidOperationException(
                    "Der LS25-Modordner konnte nicht automatisch ermittelt werden. Bitte wähle ihn manuell aus.");
            }

            Directory.CreateDirectory(directory);
            progress?.Report(new ModInstallProgress(3, "Prüfe aktuelle Mod-Version …"));

            using var service = new UpdateService(_log);
            var release = await service.GetLatestAsync(cancellationToken);
            var downloadProgress = new Progress<int>(value =>
            {
                var mapped = 5 + (int)Math.Round(value * 0.75);
                progress?.Report(new ModInstallProgress(mapped, $"Mod wird heruntergeladen … {value} %"));
            });

            var downloadedFile = await service.DownloadModPackageAsync(
                release,
                _paths,
                downloadProgress,
                cancellationToken);

            try
            {
                progress?.Report(new ModInstallProgress(82, "Mod wird geprüft …"));
                var downloaded = InspectModArchive(downloadedFile);
                if (!downloaded.IsValid || downloaded.Version is null)
                {
                    throw new InvalidDataException(
                        downloaded.Error ?? "Das heruntergeladene Mod-Paket ist ungültig.");
                }

                if (downloaded.Version.Value.CompareTo(release.ModVersion) != 0)
                {
                    throw new InvalidDataException(
                        $"Das Mod-Paket enthält Version {downloaded.Version}, erwartet wurde {release.ModVersion}.");
                }

                progress?.Report(new ModInstallProgress(88, "Mod wird installiert …"));
                InstallAtomically(downloadedFile, directory, release.ModVersion);
                progress?.Report(new ModInstallProgress(100, "LS25-Verbindung ist einsatzbereit."));
            }
            finally
            {
                TryDelete(downloadedFile);
            }

            return await GetStatusAsync(cancellationToken);
        }
        finally
        {
            _operationGate.Release();
        }
    }

    private ModManagerStatus BuildStatus(
        string directory,
        InstalledModInfo installed,
        SemanticVersion? availableVersion,
        Uri? releaseNotesUri,
        bool gameRunning,
        bool usesCustomDirectory,
        bool onlineAvailable)
    {
        var installedVersionText = installed.Version?.ToString();
        var availableVersionText = availableVersion?.ToString();

        if (!onlineAvailable)
        {
            return new ModManagerStatus(
                "offline",
                installed.IsValid ? "LS25-Verbindung installiert" : "Mod-Status nicht verfügbar",
                installed.IsValid
                    ? "Die installierte Mod wurde erkannt. Die aktuelle Online-Version konnte gerade nicht geprüft werden."
                    : "Die aktuelle Mod-Version konnte nicht von GitHub abgerufen werden. Prüfe deine Internetverbindung und versuche es erneut.",
                directory,
                installedVersionText,
                availableVersionText,
                SemanticVersion.Current.ToString(),
                gameRunning,
                false,
                "Erneut prüfen",
                usesCustomDirectory,
                releaseNotesUri?.AbsoluteUri);
        }

        if (!installed.Exists)
        {
            return new ModManagerStatus(
                "notInstalled",
                "Live-Verbindung einrichten",
                "Die benötigte LS25-Mod ist noch nicht installiert. Das Dashboard kann sie automatisch herunterladen und in den richtigen Mod-Ordner legen.",
                directory,
                null,
                availableVersionText,
                SemanticVersion.Current.ToString(),
                gameRunning,
                true,
                "Jetzt automatisch einrichten",
                usesCustomDirectory,
                releaseNotesUri?.AbsoluteUri);
        }

        if (!installed.IsValid || installed.Version is null)
        {
            return new ModManagerStatus(
                "broken",
                "Installation reparieren",
                installed.Error ?? "Die vorhandene Mod-Datei ist beschädigt oder konnte nicht gelesen werden.",
                directory,
                null,
                availableVersionText,
                SemanticVersion.Current.ToString(),
                gameRunning,
                true,
                "Installation reparieren",
                usesCustomDirectory,
                releaseNotesUri?.AbsoluteUri);
        }

        if (availableVersion is not null && installed.Version.Value.CompareTo(availableVersion.Value) < 0)
        {
            return new ModManagerStatus(
                "updateAvailable",
                "Mod-Update verfügbar",
                $"Version {availableVersion} ist verfügbar. Das Dashboard kann die installierte Version {installed.Version} automatisch ersetzen.",
                directory,
                installedVersionText,
                availableVersionText,
                SemanticVersion.Current.ToString(),
                gameRunning,
                true,
                "Mod aktualisieren",
                usesCustomDirectory,
                releaseNotesUri?.AbsoluteUri);
        }

        if (availableVersion is not null && installed.Version.Value.CompareTo(availableVersion.Value) > 0)
        {
            return new ModManagerStatus(
                "newer",
                "LS25-Verbindung installiert",
                $"Installiert ist Mod {installed.Version}. Sie ist neuer als die derzeit veröffentlichte Version {availableVersion}.",
                directory,
                installedVersionText,
                availableVersionText,
                SemanticVersion.Current.ToString(),
                gameRunning,
                false,
                "Neu prüfen",
                usesCustomDirectory,
                releaseNotesUri?.AbsoluteUri);
        }

        return new ModManagerStatus(
            "ready",
            "Live-Verbindung zu LS25",
            "Alles ist aktuell und einsatzbereit.",
            directory,
            installedVersionText,
            availableVersionText,
            SemanticVersion.Current.ToString(),
            gameRunning,
            true,
            "Mod neu installieren",
            usesCustomDirectory,
            releaseNotesUri?.AbsoluteUri);
    }

    private InstalledModInfo InspectInstalledMod(string directory)
    {
        var path = Path.Combine(directory, ModFileName);
        if (!File.Exists(path))
        {
            return new InstalledModInfo(false, false, null, null);
        }

        var result = InspectModArchive(path);
        return new InstalledModInfo(true, result.IsValid, result.Version, result.Error);
    }

    private static ModArchiveInfo InspectModArchive(string archivePath)
    {
        try
        {
            using var archive = ZipFile.OpenRead(archivePath);
            var modDesc = archive.Entries.FirstOrDefault(entry =>
                entry.FullName.Equals("modDesc.xml", StringComparison.OrdinalIgnoreCase));
            var mainScript = archive.Entries.FirstOrDefault(entry =>
                entry.FullName.Replace('\\', '/').Equals(
                    "scripts/HofDashboard.lua",
                    StringComparison.OrdinalIgnoreCase));

            if (modDesc is null || mainScript is null)
            {
                return new ModArchiveInfo(
                    false,
                    null,
                    "Die Mod-Datei ist unvollständig (modDesc.xml oder Hauptskript fehlt).");
            }

            using var stream = modDesc.Open();
            var document = XDocument.Load(stream);
            var rawVersion = document.Root?.Element("version")?.Value?.Trim();
            var normalized = NormalizeModVersion(rawVersion);
            if (!SemanticVersion.TryParse(normalized, out var version))
            {
                return new ModArchiveInfo(false, null, "Die Versionsnummer der Mod konnte nicht gelesen werden.");
            }

            return new ModArchiveInfo(true, version, null);
        }
        catch (Exception exception) when (
            exception is InvalidDataException
            or IOException
            or UnauthorizedAccessException
            or System.Xml.XmlException)
        {
            return new ModArchiveInfo(false, null, $"Die Mod-Datei konnte nicht gelesen werden: {exception.Message}");
        }
    }

    private static string NormalizeModVersion(string? rawVersion)
    {
        if (string.IsNullOrWhiteSpace(rawVersion))
        {
            return string.Empty;
        }

        var parts = rawVersion.Split('.');
        if (parts.Length == 4 && parts[3] == "0")
        {
            return string.Join('.', parts.Take(3));
        }

        return rawVersion;
    }

    private void InstallAtomically(
        string sourceArchive,
        string directory,
        SemanticVersion expectedVersion)
    {
        var target = Path.Combine(directory, ModFileName);
        var incoming = Path.Combine(directory, $".{ModFileName}.{Guid.NewGuid():N}.new");
        var backup = Path.Combine(directory, $".{ModFileName}.backup");

        TryDelete(incoming);
        TryDelete(backup);
        File.Copy(sourceArchive, incoming, overwrite: true);

        var incomingInfo = InspectModArchive(incoming);
        if (!incomingInfo.IsValid
            || incomingInfo.Version is null
            || incomingInfo.Version.Value.CompareTo(expectedVersion) != 0)
        {
            TryDelete(incoming);
            throw new InvalidDataException("Das vorbereitete Mod-Paket hat die Installationsprüfung nicht bestanden.");
        }

        try
        {
            if (File.Exists(target))
            {
                try
                {
                    File.Replace(incoming, target, backup, ignoreMetadataErrors: true);
                }
                catch (PlatformNotSupportedException)
                {
                    ReplaceWithMove(incoming, target, backup);
                }
                catch (IOException)
                {
                    ReplaceWithMove(incoming, target, backup);
                }
            }
            else
            {
                File.Move(incoming, target);
            }

            var installed = InspectModArchive(target);
            if (!installed.IsValid
                || installed.Version is null
                || installed.Version.Value.CompareTo(expectedVersion) != 0)
            {
                throw new InvalidDataException("Die installierte Mod konnte nach dem Kopieren nicht bestätigt werden.");
            }

            TryDelete(backup);
            _log.Info($"FS25-Mod {expectedVersion} installiert: {target}");
        }
        catch
        {
            TryDelete(incoming);
            if (File.Exists(backup))
            {
                TryDelete(target);
                File.Move(backup, target, overwrite: true);
            }
            throw;
        }
    }

    private static void ReplaceWithMove(string incoming, string target, string backup)
    {
        File.Move(target, backup, overwrite: true);
        try
        {
            File.Move(incoming, target, overwrite: true);
        }
        catch
        {
            File.Move(backup, target, overwrite: true);
            throw;
        }
    }

    private string? ReadCustomDirectory()
    {
        try
        {
            if (!File.Exists(CustomPathFile))
            {
                return null;
            }

            var value = File.ReadAllText(CustomPathFile).Trim();
            return string.IsNullOrWhiteSpace(value) ? null : Path.GetFullPath(value);
        }
        catch (Exception exception) when (
            exception is IOException
            or UnauthorizedAccessException
            or ArgumentException
            or NotSupportedException)
        {
            _log.Warning($"Gespeicherter Mod-Ordner konnte nicht gelesen werden: {exception.Message}");
            return null;
        }
    }

    private IEnumerable<string> GetAutomaticCandidates()
    {
        var candidates = new List<string>();
        AddDocumentsCandidate(candidates, Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments));

        var oneDrive = Environment.GetEnvironmentVariable("OneDrive");
        if (!string.IsNullOrWhiteSpace(oneDrive))
        {
            AddDocumentsCandidate(candidates, Path.Combine(oneDrive, "Documents"));
            AddDocumentsCandidate(candidates, Path.Combine(oneDrive, "Dokumente"));
        }

        var userProfile = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
        if (!string.IsNullOrWhiteSpace(userProfile))
        {
            AddDocumentsCandidate(candidates, Path.Combine(userProfile, "Documents"));
        }

        return candidates
            .Where(path => !string.IsNullOrWhiteSpace(path))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .OrderByDescending(path => Directory.Exists(path));
    }

    private static void AddDocumentsCandidate(ICollection<string> candidates, string? documentsDirectory)
    {
        if (string.IsNullOrWhiteSpace(documentsDirectory))
        {
            return;
        }

        candidates.Add(Path.GetFullPath(Path.Combine(
            documentsDirectory,
            "My Games",
            "FarmingSimulator2025",
            "mods")));
    }

    private static string NormalizeSelectedDirectory(string directory)
    {
        if (string.IsNullOrWhiteSpace(directory))
        {
            throw new ArgumentException("Es wurde kein Mod-Ordner ausgewählt.", nameof(directory));
        }

        var normalized = Path.GetFullPath(directory.Trim());
        var leaf = new DirectoryInfo(normalized).Name;
        if (leaf.Equals("FarmingSimulator2025", StringComparison.OrdinalIgnoreCase))
        {
            normalized = Path.Combine(normalized, "mods");
        }
        else if (leaf.Equals("My Games", StringComparison.OrdinalIgnoreCase))
        {
            normalized = Path.Combine(normalized, "FarmingSimulator2025", "mods");
        }

        return Path.GetFullPath(normalized);
    }

    private static bool IsGameRunning()
    {
        return Process.GetProcessesByName("FarmingSimulator2025Game").Length > 0
            || Process.GetProcessesByName("FarmingSimulator2025").Length > 0;
    }

    private static void TryDelete(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
            // Cleanup must never hide the actual installation result.
        }
    }

    private string CustomPathFile => Path.Combine(_paths.UserDataRoot, CustomPathFileName);

    private readonly record struct ModArchiveInfo(
        bool IsValid,
        SemanticVersion? Version,
        string? Error);

    private readonly record struct InstalledModInfo(
        bool Exists,
        bool IsValid,
        SemanticVersion? Version,
        string? Error);
}

internal sealed record ModManagerStatus(
    string State,
    string Title,
    string Detail,
    string Directory,
    string? InstalledVersion,
    string? AvailableVersion,
    string DashboardVersion,
    bool GameRunning,
    bool CanInstall,
    string ActionLabel,
    bool UsesCustomDirectory,
    string? ReleaseNotesUrl);

internal sealed record ModInstallProgress(int Percent, string Message);

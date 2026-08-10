using System.IO.Compression;
using System.Net.Http.Json;
using System.Security.Cryptography;

namespace HofDashboard.Launcher;

internal sealed class UpdateService : IDisposable
{
    private static readonly Uri ManifestUri = new(
        "https://github.com/philvangaatd/LS25_HofDashboard/releases/latest/download/update-manifest.json");
    private static readonly Uri ApplicationReleaseBaseUri = new(
        "https://github.com/philvangaatd/LS25_HofDashboard/releases/download/");
    private static readonly Uri ModReleaseBaseUri = new(
        "https://github.com/philvangaatd/LS25_HofDashboardMod/releases/download/");

    private readonly HttpClient _client;
    private readonly LauncherLog _log;

    public UpdateService(LauncherLog log)
    {
        _log = log;
        _client = new HttpClient
        {
            Timeout = Timeout.InfiniteTimeSpan,
        };
        _client.DefaultRequestHeaders.UserAgent.ParseAdd(
            $"LS25-HofDashboard-Updater/{SemanticVersion.Current}");
    }

    public async Task<UpdateAvailability?> CheckAsync(CancellationToken cancellationToken)
    {
        _log.Info("Prüfe öffentliches GitHub-Release auf Updates.");
        using var checkTimeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        checkTimeout.CancelAfter(TimeSpan.FromSeconds(20));
        using var response = await _client.GetAsync(ManifestUri, checkTimeout.Token);
        if (!response.IsSuccessStatusCode)
        {
            _log.Warning($"Update-Manifest ist nicht verfügbar (HTTP {(int)response.StatusCode}).");
            return null;
        }

        var manifest = await response.Content.ReadFromJsonAsync<UpdateManifest>(
            cancellationToken: checkTimeout.Token)
            ?? throw new InvalidDataException("Das Update-Manifest ist leer.");

        var update = Validate(manifest);
        var currentVersion = SemanticVersion.Current;
        if (update.Version.CompareTo(currentVersion) <= 0)
        {
            _log.Info($"Kein Update verfügbar. Installiert: {currentVersion}; aktuell: {update.Version}.");
            return null;
        }

        return update;
    }

    public async Task<PreparedUpdate> DownloadAndPrepareAsync(
        UpdateAvailability update,
        AppPaths paths,
        IProgress<int> progress,
        CancellationToken cancellationToken)
    {
        var safeVersion = update.Version.ToString();
        var archivePath = Path.Combine(paths.UpdateDownloadDirectory, $"HofDashboard-{safeVersion}.zip");
        var stagingDirectory = Path.Combine(paths.UpdateStagingDirectory, safeVersion);
        ResetDirectoryWithin(paths.UpdateStagingDirectory, stagingDirectory);

        _log.Info($"Lade Update {update.Version} herunter.");
        using (var response = await _client.GetAsync(
            update.DownloadUri,
            HttpCompletionOption.ResponseHeadersRead,
            cancellationToken))
        {
            response.EnsureSuccessStatusCode();
            await using var source = await response.Content.ReadAsStreamAsync(cancellationToken);
            await using var destination = new FileStream(
                archivePath,
                FileMode.Create,
                FileAccess.Write,
                FileShare.None,
                1024 * 128,
                useAsync: true);

            var buffer = new byte[1024 * 128];
            long totalBytes = 0;
            int bytesRead;
            while ((bytesRead = await source.ReadAsync(buffer, cancellationToken)) > 0)
            {
                await destination.WriteAsync(buffer.AsMemory(0, bytesRead), cancellationToken);
                totalBytes += bytesRead;
                progress.Report((int)Math.Clamp(totalBytes * 100 / update.SizeBytes, 0, 100));
            }
        }

        var archiveInfo = new FileInfo(archivePath);
        if (archiveInfo.Length != update.SizeBytes)
        {
            throw new InvalidDataException(
                $"Der Download ist unvollständig ({archiveInfo.Length} statt {update.SizeBytes} Bytes).");
        }

        await using (var archiveStream = File.OpenRead(archivePath))
        {
            var actualHash = Convert.ToHexString(
                await SHA256.HashDataAsync(archiveStream, cancellationToken)).ToLowerInvariant();
            if (!actualHash.Equals(update.Sha256, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException("Die SHA-256-Prüfsumme des Updates stimmt nicht.");
            }
        }

        ExtractArchiveSafely(archivePath, stagingDirectory, cancellationToken);
        await PackageManifest.LoadAndValidateAsync(stagingDirectory, update.Version, cancellationToken);
        progress.Report(100);
        _log.Info($"Update {update.Version} wurde vollständig geprüft und vorbereitet.");
        return new PreparedUpdate(update.Version, stagingDirectory);
    }

    public void Dispose() => _client.Dispose();

    private static UpdateAvailability Validate(UpdateManifest manifest)
    {
        if (manifest.SchemaVersion != 1
            || !manifest.Channel.Equals("stable", StringComparison.OrdinalIgnoreCase)
            || manifest.Compatibility.ProtocolVersion != 1)
        {
            throw new InvalidDataException("Das Update-Manifest wird von dieser App nicht unterstützt.");
        }

        var version = SemanticVersion.Parse(manifest.Application.Version);
        var modVersion = SemanticVersion.Parse(manifest.Mod.Version);
        _ = SemanticVersion.Parse(manifest.Compatibility.MinimumApplicationVersion);
        _ = SemanticVersion.Parse(manifest.Compatibility.MinimumModVersion);

        var downloadUri = ValidateReleaseUri(manifest.Application.DownloadUrl, ApplicationReleaseBaseUri);
        var releaseNotesUri = ValidateHttpsUri(manifest.Application.ReleaseNotesUrl, "github.com");
        var modDownloadUri = ValidateReleaseUri(manifest.Mod.DownloadUrl, ModReleaseBaseUri);
        if (manifest.Application.SizeBytes <= 0
            || manifest.Application.Sha256.Length != 64
            || manifest.Application.Sha256.Any(character => !Uri.IsHexDigit(character)))
        {
            throw new InvalidDataException("Das Update-Manifest enthält ungültige Paketdaten.");
        }

        return new UpdateAvailability(
            version,
            downloadUri,
            manifest.Application.Sha256.ToLowerInvariant(),
            manifest.Application.SizeBytes,
            releaseNotesUri,
            modVersion,
            modDownloadUri);
    }

    private static Uri ValidateReleaseUri(string rawUri, Uri requiredBaseUri)
    {
        var uri = ValidateHttpsUri(rawUri, "github.com");
        if (!requiredBaseUri.IsBaseOf(uri))
        {
            throw new InvalidDataException($"Nicht erlaubte Updateadresse: {uri}");
        }

        return uri;
    }

    private static Uri ValidateHttpsUri(string rawUri, string requiredHost)
    {
        if (!Uri.TryCreate(rawUri, UriKind.Absolute, out var uri)
            || uri.Scheme != Uri.UriSchemeHttps
            || !uri.Host.Equals(requiredHost, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidDataException($"Ungültige HTTPS-Adresse im Update-Manifest: {rawUri}");
        }

        return uri;
    }

    private static void ResetDirectoryWithin(string allowedRoot, string targetDirectory)
    {
        var root = Path.GetFullPath(allowedRoot).TrimEnd(Path.DirectorySeparatorChar)
            + Path.DirectorySeparatorChar;
        var target = Path.GetFullPath(targetDirectory).TrimEnd(Path.DirectorySeparatorChar)
            + Path.DirectorySeparatorChar;
        if (!target.StartsWith(root, StringComparison.OrdinalIgnoreCase) || target == root)
        {
            throw new InvalidOperationException("Unsicherer Update-Stagingpfad.");
        }

        if (Directory.Exists(targetDirectory))
        {
            Directory.Delete(targetDirectory, recursive: true);
        }
        Directory.CreateDirectory(targetDirectory);
    }

    private static void ExtractArchiveSafely(
        string archivePath,
        string targetDirectory,
        CancellationToken cancellationToken)
    {
        var targetRoot = Path.GetFullPath(targetDirectory).TrimEnd(Path.DirectorySeparatorChar)
            + Path.DirectorySeparatorChar;
        using var archive = ZipFile.OpenRead(archivePath);
        foreach (var entry in archive.Entries)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (string.IsNullOrEmpty(entry.Name))
            {
                continue;
            }

            var destination = Path.GetFullPath(Path.Combine(targetRoot, entry.FullName));
            if (!destination.StartsWith(targetRoot, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException($"Unsicherer Pfad im Updatearchiv: {entry.FullName}");
            }

            Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
            entry.ExtractToFile(destination, overwrite: true);
        }
    }
}

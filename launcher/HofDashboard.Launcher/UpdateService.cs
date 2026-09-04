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
        var update = await GetLatestAsync(cancellationToken);
        var currentVersion = SemanticVersion.Current;
        if (update.Version.CompareTo(currentVersion) <= 0)
        {
            _log.Info($"Kein Update verfügbar. Installiert: {currentVersion}; aktuell: {update.Version}.");
            return null;
        }

        return update;
    }

    public async Task<UpdateAvailability> GetLatestAsync(CancellationToken cancellationToken)
    {
        _log.Info("Prüfe öffentliches GitHub-Release auf Updates.");
        using var checkTimeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        checkTimeout.CancelAfter(TimeSpan.FromSeconds(20));
        using var response = await _client.GetAsync(ManifestUri, checkTimeout.Token);
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidDataException(
                $"Update-Manifest ist nicht verfügbar (HTTP {(int)response.StatusCode}).");
        }

        var manifest = await response.Content.ReadFromJsonAsync<UpdateManifest>(
            cancellationToken: checkTimeout.Token)
            ?? throw new InvalidDataException("Das Update-Manifest ist leer.");

        return Validate(manifest);
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
        await DownloadVerifiedFileAsync(
            update.DownloadUri,
            archivePath,
            update.SizeBytes,
            update.Sha256,
            progress,
            "Dashboard-Update",
            cancellationToken);

        ExtractArchiveSafely(archivePath, stagingDirectory, cancellationToken);
        await PackageManifest.LoadAndValidateAsync(stagingDirectory, update.Version, cancellationToken);
        progress.Report(100);
        _log.Info($"Update {update.Version} wurde vollständig geprüft und vorbereitet.");
        return new PreparedUpdate(update.Version, stagingDirectory);
    }

    public async Task<string> DownloadModPackageAsync(
        UpdateAvailability release,
        AppPaths paths,
        IProgress<int> progress,
        CancellationToken cancellationToken)
    {
        var targetPath = Path.Combine(
            paths.TempDirectory,
            $"FS25_HofDashboard-{release.ModVersion}-{Guid.NewGuid():N}.zip");

        _log.Info($"Lade FS25-Mod {release.ModVersion} herunter.");
        await DownloadVerifiedFileAsync(
            release.ModDownloadUri,
            targetPath,
            release.ModSizeBytes,
            release.ModSha256,
            progress,
            "FS25-Mod",
            cancellationToken);
        _log.Info($"FS25-Mod {release.ModVersion} wurde anhand von Größe und SHA-256 geprüft.");
        return targetPath;
    }

    public void Dispose() => _client.Dispose();

    private async Task DownloadVerifiedFileAsync(
        Uri sourceUri,
        string targetPath,
        long expectedSize,
        string expectedSha256,
        IProgress<int> progress,
        string packageLabel,
        CancellationToken cancellationToken)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(targetPath)!);
        using (var response = await _client.GetAsync(
            sourceUri,
            HttpCompletionOption.ResponseHeadersRead,
            cancellationToken))
        {
            response.EnsureSuccessStatusCode();
            await using var source = await response.Content.ReadAsStreamAsync(cancellationToken);
            await using var destination = new FileStream(
                targetPath,
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
                progress.Report((int)Math.Clamp(totalBytes * 100 / expectedSize, 0, 100));
            }
        }

        var fileInfo = new FileInfo(targetPath);
        if (fileInfo.Length != expectedSize)
        {
            TryDelete(targetPath);
            throw new InvalidDataException(
                $"{packageLabel}: Download ist unvollständig ({fileInfo.Length} statt {expectedSize} Bytes).");
        }

        await using var fileStream = File.OpenRead(targetPath);
        var actualHash = Convert.ToHexString(
            await SHA256.HashDataAsync(fileStream, cancellationToken)).ToLowerInvariant();
        if (!actualHash.Equals(expectedSha256, StringComparison.OrdinalIgnoreCase))
        {
            TryDelete(targetPath);
            throw new InvalidDataException(
                $"{packageLabel}: Die SHA-256-Prüfsumme stimmt nicht.");
        }
    }

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
        var modReleaseNotesUri = ValidateHttpsUri(manifest.Mod.ReleaseNotesUrl, "github.com");

        ValidatePackageMetadata(
            manifest.Application.SizeBytes,
            manifest.Application.Sha256,
            "Dashboard");
        ValidatePackageMetadata(
            manifest.Mod.SizeBytes,
            manifest.Mod.Sha256,
            "Mod");

        return new UpdateAvailability(
            version,
            downloadUri,
            manifest.Application.Sha256.ToLowerInvariant(),
            manifest.Application.SizeBytes,
            releaseNotesUri,
            modVersion,
            modDownloadUri,
            manifest.Mod.Sha256.ToLowerInvariant(),
            manifest.Mod.SizeBytes,
            modReleaseNotesUri);
    }

    private static void ValidatePackageMetadata(long sizeBytes, string sha256, string label)
    {
        if (sizeBytes <= 0
            || string.IsNullOrWhiteSpace(sha256)
            || sha256.Length != 64
            || sha256.Any(character => !Uri.IsHexDigit(character)))
        {
            throw new InvalidDataException(
                $"Das Update-Manifest enthält ungültige Paketdaten für {label}.");
        }
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
            // A failed cleanup must not replace the useful verification error.
        }
    }
}

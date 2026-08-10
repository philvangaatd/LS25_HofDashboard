using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace HofDashboard.Launcher;

internal sealed record PackageManifest(
    [property: JsonPropertyName("schemaVersion")] int SchemaVersion,
    [property: JsonPropertyName("applicationVersion")] string ApplicationVersion,
    [property: JsonPropertyName("files")] IReadOnlyList<PackageFile> Files)
{
    public const string FileName = "package-files.json";

    public static async Task<PackageManifest> LoadAndValidateAsync(
        string packageDirectory,
        SemanticVersion? expectedVersion,
        CancellationToken cancellationToken)
    {
        var root = Path.GetFullPath(packageDirectory);
        var manifestPath = Path.Combine(root, FileName);
        await using var stream = File.OpenRead(manifestPath);
        var manifest = await JsonSerializer.DeserializeAsync<PackageManifest>(
            stream,
            cancellationToken: cancellationToken)
            ?? throw new InvalidDataException("Das Paketdatei-Manifest ist leer.");

        if (manifest.SchemaVersion != 1 || manifest.Files.Count == 0)
        {
            throw new InvalidDataException("Das Paketdatei-Manifest wird nicht unterstützt.");
        }

        var manifestVersion = SemanticVersion.Parse(manifest.ApplicationVersion);
        if (expectedVersion is not null && manifestVersion.CompareTo(expectedVersion.Value) != 0)
        {
            throw new InvalidDataException(
                $"Das Updatepaket enthält Version {manifestVersion} statt {expectedVersion}.");
        }

        var uniquePaths = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var file in manifest.Files)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var fullPath = ResolvePackagePath(root, file.Path);
            if (!uniquePaths.Add(file.Path)
                || file.SizeBytes < 0
                || file.Sha256.Length != 64
                || file.Sha256.Any(character => !Uri.IsHexDigit(character))
                || !File.Exists(fullPath))
            {
                throw new InvalidDataException($"Ungültiger Paketeintrag: {file.Path}");
            }

            var info = new FileInfo(fullPath);
            if (info.Length != file.SizeBytes)
            {
                throw new InvalidDataException($"Unerwartete Dateigröße im Updatepaket: {file.Path}");
            }

            await using var fileStream = File.OpenRead(fullPath);
            var actualHash = Convert.ToHexString(await SHA256.HashDataAsync(fileStream, cancellationToken))
                .ToLowerInvariant();
            if (!actualHash.Equals(file.Sha256, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException($"Prüfsumme stimmt nicht: {file.Path}");
            }
        }

        foreach (var requiredPath in new[]
        {
            "HofDashboard.exe",
            "launcher-manifest.json",
            "runtime/php.exe",
            "web/app-manifest.json",
            "web/health.php",
        })
        {
            if (!uniquePaths.Contains(requiredPath))
            {
                throw new InvalidDataException($"Erforderliche Paketdatei fehlt: {requiredPath}");
            }
        }

        return manifest;
    }

    public static string ResolvePackagePath(string rootDirectory, string relativePath)
    {
        if (string.IsNullOrWhiteSpace(relativePath)
            || Path.IsPathRooted(relativePath)
            || relativePath.Contains(':'))
        {
            throw new InvalidDataException($"Unsicherer Paketpfad: {relativePath}");
        }

        var normalizedRelativePath = relativePath.Replace('/', Path.DirectorySeparatorChar);
        var root = Path.GetFullPath(rootDirectory).TrimEnd(Path.DirectorySeparatorChar)
            + Path.DirectorySeparatorChar;
        var fullPath = Path.GetFullPath(Path.Combine(root, normalizedRelativePath));
        if (!fullPath.StartsWith(root, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidDataException($"Paketpfad verlässt den Zielordner: {relativePath}");
        }

        return fullPath;
    }
}

internal sealed record PackageFile(
    [property: JsonPropertyName("path")] string Path,
    [property: JsonPropertyName("sha256")] string Sha256,
    [property: JsonPropertyName("sizeBytes")] long SizeBytes);

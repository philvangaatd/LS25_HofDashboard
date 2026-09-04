using System.Text.Json.Serialization;

namespace HofDashboard.Launcher;

internal sealed record UpdateManifest(
    [property: JsonPropertyName("schemaVersion")] int SchemaVersion,
    [property: JsonPropertyName("channel")] string Channel,
    [property: JsonPropertyName("publishedAt")] DateTimeOffset PublishedAt,
    [property: JsonPropertyName("application")] ApplicationRelease Application,
    [property: JsonPropertyName("mod")] ModRelease Mod,
    [property: JsonPropertyName("compatibility")] UpdateCompatibility Compatibility);

internal sealed record ApplicationRelease(
    [property: JsonPropertyName("version")] string Version,
    [property: JsonPropertyName("downloadUrl")] string DownloadUrl,
    [property: JsonPropertyName("sha256")] string Sha256,
    [property: JsonPropertyName("sizeBytes")] long SizeBytes,
    [property: JsonPropertyName("releaseNotesUrl")] string ReleaseNotesUrl);

internal sealed record ModRelease(
    [property: JsonPropertyName("version")] string Version,
    [property: JsonPropertyName("downloadUrl")] string DownloadUrl,
    [property: JsonPropertyName("sha256")] string Sha256,
    [property: JsonPropertyName("sizeBytes")] long SizeBytes,
    [property: JsonPropertyName("releaseNotesUrl")] string ReleaseNotesUrl);

internal sealed record UpdateCompatibility(
    [property: JsonPropertyName("protocolVersion")] int ProtocolVersion,
    [property: JsonPropertyName("minimumApplicationVersion")] string MinimumApplicationVersion,
    [property: JsonPropertyName("minimumModVersion")] string MinimumModVersion);

internal sealed record UpdateAvailability(
    SemanticVersion Version,
    Uri DownloadUri,
    string Sha256,
    long SizeBytes,
    Uri ReleaseNotesUri,
    SemanticVersion ModVersion,
    Uri ModDownloadUri,
    string ModSha256,
    long ModSizeBytes,
    Uri ModReleaseNotesUri);

internal sealed record PreparedUpdate(
    SemanticVersion Version,
    string StagingDirectory);

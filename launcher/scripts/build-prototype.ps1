param(
    [string]$Configuration = "Release",
    [string]$ArtifactDirectory = ""
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$launcherRoot = Split-Path -Parent $PSScriptRoot
$repositoryRoot = Split-Path -Parent $launcherRoot
if ([string]::IsNullOrWhiteSpace($ArtifactDirectory)) {
    $ArtifactDirectory = Join-Path $repositoryRoot "dist"
}

$project = Join-Path $launcherRoot "HofDashboard.Launcher\HofDashboard.Launcher.csproj"
$publishDirectory = Join-Path $ArtifactDirectory "launcher-publish"
$dashboardManifestSource = Get-Content -Raw -LiteralPath (Join-Path $repositoryRoot "app-manifest.json") | ConvertFrom-Json
$applicationVersion = [string]$dashboardManifestSource.version
$packageName = "HofDashboard-win-x64-v$applicationVersion"
$packageDirectory = Join-Path $ArtifactDirectory $packageName
$packageZip = Join-Path $ArtifactDirectory "$packageName.zip"
$updateManifestPath = Join-Path $ArtifactDirectory "update-manifest.json"
$runtimeDirectory = Join-Path $packageDirectory "runtime"
$webDirectory = Join-Path $packageDirectory "web"

$phpVersion = "8.5.9"
$phpUrl = "https://downloads.php.net/~windows/releases/archives/php-$phpVersion-nts-Win32-vs17-x64.zip"
$phpSha256 = "516c2d72231bd035c8a910120834add0ad208098b790b4909b2cbeb93ce135fc"
$downloadRoot = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [System.IO.Path]::GetTempPath() }
$phpArchive = Join-Path $downloadRoot "php-$phpVersion-nts-Win32-vs17-x64.zip"

foreach ($path in @($publishDirectory, $packageDirectory, $packageZip, $updateManifestPath)) {
    if (Test-Path $path) {
        Remove-Item -LiteralPath $path -Recurse -Force
    }
}

New-Item -ItemType Directory -Force -Path $ArtifactDirectory | Out-Null
New-Item -ItemType Directory -Force -Path $packageDirectory | Out-Null
New-Item -ItemType Directory -Force -Path $webDirectory | Out-Null

Write-Host "Publishing self-contained Windows launcher..."
dotnet publish $project `
    --configuration $Configuration `
    --runtime win-x64 `
    --self-contained true `
    --output $publishDirectory `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:DebugType=None `
    -p:DebugSymbols=false
if ($LASTEXITCODE -ne 0) {
    throw "dotnet publish failed with exit code $LASTEXITCODE."
}

Copy-Item -Path (Join-Path $publishDirectory "*") -Destination $packageDirectory -Recurse -Force
Copy-Item -LiteralPath (Join-Path $launcherRoot "launcher-manifest.json") -Destination $packageDirectory
Copy-Item -LiteralPath (Join-Path $launcherRoot "THIRD-PARTY-NOTICES.md") -Destination $packageDirectory

Write-Host "Assembling dashboard web files..."
$excludedRootEntries = @(".git", ".github", ".gitignore", "backups", "dist", "launcher")
Get-ChildItem -LiteralPath $repositoryRoot -Force |
    Where-Object { $_.Name -notin $excludedRootEntries } |
    ForEach-Object {
        Copy-Item -LiteralPath $_.FullName -Destination $webDirectory -Recurse -Force
    }

Write-Host "Downloading verified PHP $phpVersion runtime..."
$archiveIsValid = $false
if (Test-Path $phpArchive) {
    $archiveIsValid = (Get-FileHash -Algorithm SHA256 -LiteralPath $phpArchive).Hash.ToLowerInvariant() -eq $phpSha256
}
if (-not $archiveIsValid) {
    Invoke-WebRequest -Uri $phpUrl -OutFile $phpArchive
}

$actualHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $phpArchive).Hash.ToLowerInvariant()
if ($actualHash -ne $phpSha256) {
    throw "PHP archive checksum mismatch. Expected $phpSha256, got $actualHash."
}

Expand-Archive -LiteralPath $phpArchive -DestinationPath $runtimeDirectory -Force
Copy-Item -LiteralPath (Join-Path $launcherRoot "php.ini") -Destination $runtimeDirectory -Force

$phpExecutable = Join-Path $runtimeDirectory "php.exe"
$phpConfiguration = Join-Path $runtimeDirectory "php.ini"
$requiredRuntimeFiles = @(
    $phpExecutable,
    $phpConfiguration,
    (Join-Path $runtimeDirectory "ext\php_curl.dll"),
    (Join-Path $runtimeDirectory "ext\php_fileinfo.dll"),
    (Join-Path $runtimeDirectory "ext\php_gd.dll"),
    (Join-Path $runtimeDirectory "ext\php_mbstring.dll"),
    (Join-Path $runtimeDirectory "ext\php_openssl.dll"),
    (Join-Path $runtimeDirectory "ext\php_zip.dll")
)
foreach ($requiredFile in $requiredRuntimeFiles) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "Required runtime file is missing: $requiredFile"
    }
}

Write-Host "Validating bundled PHP modules and dashboard syntax..."
$launcherManifest = Get-Content -Raw -LiteralPath (Join-Path $launcherRoot "launcher-manifest.json") | ConvertFrom-Json
$dashboardManifest = Get-Content -Raw -LiteralPath (Join-Path $webDirectory "app-manifest.json") | ConvertFrom-Json
[xml]$projectXml = Get-Content -Raw -LiteralPath $project
$projectVersion = [string]$projectXml.Project.PropertyGroup.Version
if (($launcherManifest.version -ne $projectVersion) -or
    ($launcherManifest.phpVersion -ne $phpVersion) -or
    ($launcherManifest.dashboardVersion -ne $dashboardManifest.version)) {
    throw "Launcher, PHP and dashboard version manifests are inconsistent."
}

$moduleOutput = & $phpExecutable -c $phpConfiguration -m
if ($LASTEXITCODE -ne 0) {
    throw "Bundled PHP failed to load its configuration."
}
$requiredModules = @("curl", "dom", "fileinfo", "gd", "libxml", "mbstring", "openssl", "session", "SimpleXML", "zip")
foreach ($requiredModule in $requiredModules) {
    if ($moduleOutput -notcontains $requiredModule) {
        throw "Required PHP module is not loaded: $requiredModule"
    }
}

Get-ChildItem -LiteralPath $webDirectory -Filter "*.php" -File | ForEach-Object {
    & $phpExecutable -c $phpConfiguration -l $_.FullName
    if ($LASTEXITCODE -ne 0) {
        throw "PHP syntax validation failed: $($_.FullName)"
    }
}

$requiredPackageFiles = @(
    (Join-Path $packageDirectory "HofDashboard.exe"),
    (Join-Path $packageDirectory "launcher-manifest.json"),
    (Join-Path $runtimeDirectory "php.exe"),
    (Join-Path $webDirectory "index.html"),
    (Join-Path $webDirectory "api.php"),
    (Join-Path $webDirectory "health.php"),
    (Join-Path $webDirectory "app-manifest.json")
)
foreach ($requiredFile in $requiredPackageFiles) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "Required package file is missing: $requiredFile"
    }
}

Write-Host "Creating verified package file manifest..."
$packageFiles = Get-ChildItem -LiteralPath $packageDirectory -File -Recurse |
    Where-Object { $_.Name -ne "package-files.json" } |
    ForEach-Object {
        [ordered]@{
            path = [System.IO.Path]::GetRelativePath($packageDirectory, $_.FullName).Replace("\", "/")
            sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $_.FullName).Hash.ToLowerInvariant()
            sizeBytes = $_.Length
        }
    } |
    Sort-Object -Property path

$packageFileManifest = [ordered]@{
    schemaVersion = 1
    applicationVersion = $applicationVersion
    files = @($packageFiles)
}
$packageFileManifest |
    ConvertTo-Json -Depth 5 |
    Set-Content -LiteralPath (Join-Path $packageDirectory "package-files.json") -Encoding utf8NoBOM

Write-Host "Creating Windows release ZIP..."
Compress-Archive -Path (Join-Path $packageDirectory "*") -DestinationPath $packageZip -CompressionLevel Optimal

$packageHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $packageZip).Hash.ToLowerInvariant()
$packageSize = (Get-Item -LiteralPath $packageZip).Length
$releaseTag = "v$applicationVersion"
$releaseBaseUrl = "https://github.com/philvangaatd/LS25_HofDashboard/releases"
$modReleaseBaseUrl = "https://github.com/philvangaatd/LS25_HofDashboardMod/releases"
$updateManifest = [ordered]@{
    schemaVersion = 1
    channel = "stable"
    publishedAt = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
    application = [ordered]@{
        version = $applicationVersion
        downloadUrl = "$releaseBaseUrl/download/$releaseTag/$packageName.zip"
        sha256 = $packageHash
        sizeBytes = $packageSize
        releaseNotesUrl = "$releaseBaseUrl/tag/$releaseTag"
    }
    mod = [ordered]@{
        version = [string]$dashboardManifestSource.minimumModVersion
        downloadUrl = "$modReleaseBaseUrl/download/$releaseTag/FS25_HofDashboard.zip"
        releaseNotesUrl = "$modReleaseBaseUrl/tag/$releaseTag"
    }
    compatibility = [ordered]@{
        protocolVersion = [int]$dashboardManifestSource.apiProtocol.max
        minimumApplicationVersion = $applicationVersion
        minimumModVersion = [string]$dashboardManifestSource.minimumModVersion
    }
}
$updateManifest |
    ConvertTo-Json -Depth 8 |
    Set-Content -LiteralPath $updateManifestPath -Encoding utf8NoBOM

Write-Host "Windows package: $packageZip"
Write-Host "SHA-256: $packageHash"
Write-Host "Update manifest: $updateManifestPath"

param(
    [Parameter(Mandatory = $true)][string]$ManifestPath
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

function Write-Utf8NoBomJson {
    param(
        [Parameter(Mandatory = $true)][object]$Value,
        [Parameter(Mandatory = $true)][string]$Path
    )

    $json = $Value | ConvertTo-Json -Depth 10
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $json, $encoding)
}

if (-not (Test-Path -LiteralPath $ManifestPath -PathType Leaf)) {
    throw "Update manifest does not exist: $ManifestPath"
}

$manifest = Get-Content -Raw -LiteralPath $ManifestPath | ConvertFrom-Json
if ($null -eq $manifest.mod -or [string]::IsNullOrWhiteSpace([string]$manifest.mod.downloadUrl)) {
    throw "Update manifest does not contain a mod download URL."
}

$expectedVersion = [string]$manifest.mod.version
$temporaryArchive = Join-Path ([System.IO.Path]::GetTempPath()) "FS25_HofDashboard-$expectedVersion-$([Guid]::NewGuid().ToString('N')).zip"

try {
    Write-Host "Downloading matching mod release for verified manifest metadata..."
    Invoke-WebRequest -Uri ([string]$manifest.mod.downloadUrl) -OutFile $temporaryArchive

    $sizeBytes = (Get-Item -LiteralPath $temporaryArchive).Length
    if ($sizeBytes -le 0) {
        throw "Downloaded mod archive is empty."
    }

    $sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $temporaryArchive).Hash.ToLowerInvariant()

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($temporaryArchive)
    try {
        $modDescEntry = $archive.Entries | Where-Object { $_.FullName -ieq 'modDesc.xml' } | Select-Object -First 1
        $mainScriptEntry = $archive.Entries | Where-Object { $_.FullName.Replace('\', '/') -ieq 'scripts/HofDashboard.lua' } | Select-Object -First 1
        if ($null -eq $modDescEntry -or $null -eq $mainScriptEntry) {
            throw "Downloaded mod archive is incomplete."
        }

        $reader = New-Object System.IO.StreamReader($modDescEntry.Open())
        try {
            [xml]$modDesc = $reader.ReadToEnd()
        }
        finally {
            $reader.Dispose()
        }

        $archiveVersion = [string]$modDesc.modDesc.version
        $normalizedArchiveVersion = $archiveVersion
        if ($archiveVersion -match '^(\d+)\.(\d+)\.(\d+)\.0$') {
            $normalizedArchiveVersion = "$($Matches[1]).$($Matches[2]).$($Matches[3])"
        }

        if ($normalizedArchiveVersion -ne $expectedVersion) {
            throw "Mod archive version mismatch. Expected $expectedVersion, got $archiveVersion."
        }
    }
    finally {
        $archive.Dispose()
    }

    $manifest.mod | Add-Member -NotePropertyName sha256 -NotePropertyValue $sha256 -Force
    $manifest.mod | Add-Member -NotePropertyName sizeBytes -NotePropertyValue $sizeBytes -Force
    Write-Utf8NoBomJson -Value $manifest -Path $ManifestPath

    Write-Host "Mod version: $expectedVersion"
    Write-Host "Mod size: $sizeBytes bytes"
    Write-Host "Mod SHA-256: $sha256"
}
finally {
    if (Test-Path -LiteralPath $temporaryArchive) {
        Remove-Item -LiteralPath $temporaryArchive -Force
    }
}

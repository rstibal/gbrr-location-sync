<#
.SYNOPSIS
    Builds an installable WordPress plugin zip from the committed source.

.DESCRIPTION
    Uses `git archive` instead of Compress-Archive because Compress-Archive
    (and naive zipping in general on Windows) embeds literal backslashes in
    zip entry names instead of the forward slashes the zip format requires.
    A zip like that extracts fine on Windows but breaks on the Linux hosts
    WordPress normally runs on -- files land with backslashes IN their
    filenames instead of in subfolders. `git archive` always writes
    forward-slash paths regardless of host OS, so it's used here
    unconditionally -- never swap this back to Compress-Archive.

    The zip is built from the given git ref (default: HEAD), with every file
    placed under a top-level "gbrr-location-sync/" folder, which is what
    WordPress's plugin installer requires.

.PARAMETER Ref
    Git ref to archive (branch, tag, or commit). Defaults to HEAD.

.EXAMPLE
    ./build-release.ps1
    ./build-release.ps1 -Ref v1.2.3
#>
param(
    [string]$Ref = 'HEAD'
)

$ErrorActionPreference = 'Stop'

$repoRoot = git rev-parse --show-toplevel
if ($LASTEXITCODE -ne 0) {
    throw "Not inside a git repository."
}
Set-Location $repoRoot

$pluginFile = Join-Path $repoRoot 'gbrr-location-sync.php'
$versionLine = Select-String -Path $pluginFile -Pattern '^\s*\*\s*Version:\s*([0-9][\w.\-]*)' | Select-Object -First 1
if (-not $versionLine) {
    throw "Could not find a Version: header in gbrr-location-sync.php"
}
$version = $versionLine.Matches[0].Groups[1].Value

$distDir = Join-Path $repoRoot 'dist'
New-Item -ItemType Directory -Force -Path $distDir | Out-Null

$slug = 'gbrr-location-sync'
$zipPath = Join-Path $distDir "$slug-$version.zip"

if (Test-Path $zipPath) {
    Remove-Item -Force $zipPath
}

Write-Host "Building $zipPath from ref '$Ref' ..."
git archive --format=zip --prefix="$slug/" --output="$zipPath" $Ref
if ($LASTEXITCODE -ne 0) {
    throw "git archive failed."
}

# Verify the zip is installable: every entry must use forward slashes and
# live under the expected top-level folder. This is the actual guard
# against the backslash-filename problem -- fail loudly rather than
# shipping a broken zip.
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $badEntries = @()
    foreach ($entry in $zip.Entries) {
        if ($entry.FullName -match '\\') {
            $badEntries += $entry.FullName
        }
        if ($entry.FullName -notlike "$slug/*") {
            $badEntries += $entry.FullName
        }
    }
    if ($badEntries.Count -gt 0) {
        throw "Zip contains bad entries (backslashes or wrong top-level folder):`n$($badEntries -join "`n")"
    }
    $entryCount = $zip.Entries.Count
} finally {
    $zip.Dispose()
}

Write-Host "OK: $entryCount entries, all forward-slash paths under '$slug/'."
Write-Host "Release zip: $zipPath"

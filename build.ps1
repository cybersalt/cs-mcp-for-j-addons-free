# cs-mcp-for-j-addons-free / cs-mcp-for-j-addons-pro — build script
#
# Auto-discovers every `plg_system_csmcpforj*` subfolder in the repo root and
# emits a standalone Joomla-installable zip for each, named after the folder
# and the add-on's own manifest <version>.
#
# Default (test build): <addonfolder>_v<version>_<yyyymmdd>_<hhmm>.zip
#   so every iteration is uniquely named and dated zips stack up locally.
#
# -Release: <addonfolder>_v<version>.zip  (stable filename, no date)
#   This is the artifact that gets uploaded to cs-release-manager on
#   cybersalt.com (which keys downloads off a stable filename, not a dated one).
#
# Per-add-on independence: each add-on carries its own <version>, so editing
# one add-on's source and rebuilding only re-emits THAT add-on's zip. Others
# are skipped via the "source unchanged" check (the existing dated zip for
# that version is newer than every file under its source folder → reuse).
# Ship the new zip through cs-release-manager and only sites running THAT
# add-on see "update available" — the other add-ons stay silent.
#
# Requires 7-Zip at the default install path. PowerShell's built-in
# Compress-Archive does NOT create directory entries, which Joomla refuses.
# See Joomla-Brain/PACKAGE-BUILD-NOTES.md.

param(
    [switch]$Release
)

$ErrorActionPreference = 'Stop'

$root     = $PSScriptRoot
$sevenZip = 'C:\Program Files\7-Zip\7z.exe'

if (-not (Test-Path $sevenZip)) {
    throw "7-Zip not found at $sevenZip. Install 7-Zip or edit build.ps1."
}

$timestamp = Get-Date -Format 'yyyyMMdd_HHmm'

# Auto-discover every add-on subfolder. Convention: each is a Joomla system
# plugin folder named `plg_system_csmcpforj<something>` with a manifest XML at
# `<folder>/csmcpforj<something>.xml` (Joomla's per-plugin manifest pattern).
$addonFolders = Get-ChildItem -Path $root -Directory -Filter 'plg_system_csmcpforj*'

if ($addonFolders.Count -eq 0) {
    Write-Host "No add-on folders found in $root (expected plg_system_csmcpforj*)." -ForegroundColor Yellow
    exit 0
}

$built = @()

foreach ($folder in $addonFolders) {
    $srcPath = $folder.FullName
    $folderName = $folder.Name

    # Plugin manifest name = folder name minus the leading 'plg_system_' prefix.
    # i.e. plg_system_csmcpforj4seo → csmcpforj4seo.xml
    $manifestName = $folderName -replace '^plg_system_', ''
    $manifestPath = Join-Path $srcPath "$manifestName.xml"

    if (-not (Test-Path $manifestPath)) {
        Write-Host "  SKIPPING $folderName : manifest $manifestName.xml not found" -ForegroundColor Yellow
        continue
    }

    [xml]$manifest = Get-Content $manifestPath
    $version = $manifest.extension.version
    if ([string]::IsNullOrWhiteSpace($version)) {
        Write-Host "  SKIPPING $folderName : <version> missing from manifest" -ForegroundColor Yellow
        continue
    }

    if ($Release) {
        $outZip = Join-Path $root "${folderName}_v${version}.zip"
    } else {
        $outZip = Join-Path $root "${folderName}_v${version}_${timestamp}.zip"
    }

    # Source-unchanged check: if a dated zip for this same version is newer
    # than every source file in the add-on folder, reuse it. Saves spamming
    # cs-release-manager with identical-content uploads when only one of the
    # add-ons changed but the whole repo gets a rebuild. -Release always rebuilds.
    if (-not $Release) {
        $latestSourceFile = Get-ChildItem $srcPath -Recurse -File -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1

        if ($latestSourceFile) {
            $existingZip = Get-ChildItem $root -Filter "${folderName}_v${version}_*.zip" -File -ErrorAction SilentlyContinue |
                Sort-Object LastWriteTime -Descending |
                Select-Object -First 1

            if ($existingZip -and $existingZip.LastWriteTime -gt $latestSourceFile.LastWriteTime) {
                Write-Host "  reusing (source unchanged): $($existingZip.Name)" -ForegroundColor DarkGray
                $built += $existingZip.FullName
                continue
            }
        }
    }

    if (Test-Path $outZip) { Remove-Item $outZip -Force }

    Write-Host "  building $folderName v$version" -ForegroundColor Cyan

    Push-Location $srcPath
    try {
        & $sevenZip a -tzip -mx=9 -bso0 -bsp0 $outZip * | Out-Null
    } finally {
        Pop-Location
    }

    $built += $outZip
}

Write-Host ""
Write-Host "Standalone add-ons built:" -ForegroundColor Green
foreach ($z in $built) {
    Write-Host "  $(Split-Path -Leaf $z)" -ForegroundColor Gray
}

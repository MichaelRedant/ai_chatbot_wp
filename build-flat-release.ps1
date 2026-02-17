param(
    [string]$OutputZip = ""
)

$ErrorActionPreference = "Stop"

$pluginRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$distDir = Join-Path $pluginRoot "dist"
$stagingRoot = Join-Path $pluginRoot (".build-flat-release-" + [DateTime]::UtcNow.Ticks.ToString())

if ([string]::IsNullOrWhiteSpace($OutputZip)) {
    $OutputZip = Join-Path $distDir "ai-chatbot-flat-release.zip"
} elseif (-not [System.IO.Path]::IsPathRooted($OutputZip)) {
    $OutputZip = Join-Path $pluginRoot $OutputZip
}

if (-not (Test-Path $distDir)) {
    New-Item -ItemType Directory -Path $distDir | Out-Null
}
if (Test-Path $OutputZip) {
    Remove-Item -Force $OutputZip
}

New-Item -ItemType Directory -Path $stagingRoot | Out-Null

$excludeNames = @(
    "dist",
    ".git",
    "wordpress-stubs",
    ".gitignore",
    "AGENTS.md",
    "prompt.md",
    "build-release.ps1",
    "build-flat-release.ps1",
    "build-release-posix.py",
    "composer.json",
    "composer.lock",
    "roadmap.md",
    "Woordenlijst Octopus.pdf"
)

Get-ChildItem -Force $pluginRoot | ForEach-Object {
    $name = $_.Name
    if ($name.StartsWith(".build-release") -or $name.StartsWith(".build-flat-release")) {
        return
    }
    if ($excludeNames -contains $name) {
        return
    }

    Copy-Item -Path $_.FullName -Destination $stagingRoot -Recurse -Force
}

$removePaths = @(
    "vendor\php-stubs",
    "vendor\bin"
)
foreach ($relativePath in $removePaths) {
    $candidate = Join-Path $stagingRoot $relativePath
    if (Test-Path $candidate) {
        Remove-Item -Recurse -Force $candidate
    }
}

# Flat package: zip de inhoud, niet de root-map.
Compress-Archive -Path (Join-Path $stagingRoot "*") -DestinationPath $OutputZip -Force

if (Test-Path $stagingRoot) {
    try {
        Remove-Item -Recurse -Force $stagingRoot -ErrorAction Stop
    } catch {
        # Best effort cleanup.
    }
}

$zipSizeMb = [math]::Round((Get-Item $OutputZip).Length / 1MB, 2)
Write-Output ("Flat release ZIP gemaakt: {0} ({1} MB)" -f $OutputZip, $zipSizeMb)

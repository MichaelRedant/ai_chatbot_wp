param(
    [string]$OutputZip = "",
    [string]$PackageFolderName = "ai-chatbot"
)

$ErrorActionPreference = "Stop"

$pluginRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$distDir = Join-Path $pluginRoot "dist"
$stagingRootBase = Join-Path $pluginRoot ".build-release"
$stagingRoot = $stagingRootBase
$packageFolderName = [string]$PackageFolderName
$packageFolderName = $packageFolderName.Trim()
if ([string]::IsNullOrWhiteSpace($packageFolderName)) {
    $packageFolderName = "ai-chatbot"
}

# Defensief: enkel veilige mapnaamtekens toelaten voor plugin slug.
$packageFolderName = ($packageFolderName -replace '[^A-Za-z0-9._-]', '-')
$packageFolderName = $packageFolderName.Trim('.')
if ([string]::IsNullOrWhiteSpace($packageFolderName)) {
    $packageFolderName = "ai-chatbot"
}

$packageRoot = Join-Path $stagingRoot $packageFolderName

if ([string]::IsNullOrWhiteSpace($OutputZip)) {
    $OutputZip = Join-Path $distDir "ai-chatbot-release.zip"
} elseif (-not [System.IO.Path]::IsPathRooted($OutputZip)) {
    $OutputZip = Join-Path $pluginRoot $OutputZip
}

if (Test-Path $stagingRootBase) {
    try {
        Remove-Item -Recurse -Force $stagingRootBase -ErrorAction Stop
    } catch {
        $stagingRoot = Join-Path $pluginRoot (".build-release-" + [DateTime]::UtcNow.Ticks.ToString())
    }
}
if (-not (Test-Path $distDir)) {
    New-Item -ItemType Directory -Path $distDir | Out-Null
}
if (Test-Path $OutputZip) {
    Remove-Item -Force $OutputZip
}

New-Item -ItemType Directory -Path $packageRoot | Out-Null

Get-ChildItem -Force $pluginRoot | ForEach-Object {
    $name = $_.Name
    if ($name -eq "dist" -or $name -eq ".git" -or $name.StartsWith(".build-release")) {
        return
    }
    Copy-Item -Path $_.FullName -Destination $packageRoot -Recurse -Force
}

$removePaths = @(
    "wordpress-stubs",
    "vendor\php-stubs",
    "vendor\bin",
    ".git",
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

foreach ($relativePath in $removePaths) {
    $candidate = Join-Path $packageRoot $relativePath
    if (Test-Path $candidate) {
        Remove-Item -Recurse -Force $candidate
    }
}

Compress-Archive -Path (Join-Path $stagingRoot $packageFolderName) -DestinationPath $OutputZip -Force

if (Test-Path $stagingRoot) {
    try {
        Remove-Item -Recurse -Force $stagingRoot -ErrorAction Stop
    } catch {
        # Best effort cleanup: lock mag build niet blokkeren.
    }
}

$zipSizeMb = [math]::Round((Get-Item $OutputZip).Length / 1MB, 2)
Write-Output ("Release ZIP gemaakt: {0} ({1} MB)" -f $OutputZip, $zipSizeMb)

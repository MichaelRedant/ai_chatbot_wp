param(
    [string]$OutputZip = ""
)

$ErrorActionPreference = "Stop"

$pluginRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$distDir = Join-Path $pluginRoot "dist"
$stagingRoot = Join-Path $pluginRoot ".build-release"
$packageFolderName = Split-Path -Leaf $pluginRoot
$packageRoot = Join-Path $stagingRoot $packageFolderName

if ([string]::IsNullOrWhiteSpace($OutputZip)) {
    $OutputZip = Join-Path $distDir "ai-chatbot-release.zip"
} elseif (-not [System.IO.Path]::IsPathRooted($OutputZip)) {
    $OutputZip = Join-Path $pluginRoot $OutputZip
}

if (Test-Path $stagingRoot) {
    Remove-Item -Recurse -Force $stagingRoot
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
    if ($name -in @(".build-release", "dist", ".git")) {
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

$zipSizeMb = [math]::Round((Get-Item $OutputZip).Length / 1MB, 2)
Write-Output ("Release ZIP gemaakt: {0} ({1} MB)" -f $OutputZip, $zipSizeMb)

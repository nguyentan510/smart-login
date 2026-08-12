# OmniWP Production Release Packaging Script
$ErrorActionPreference = "Stop"

$PluginDir = Split-Path -Parent $PSScriptRoot
$DistDir   = Join-Path $PluginDir "dist"
$TempDir   = Join-Path $DistDir "omniwp"
$ZipFile   = Join-Path $DistDir "omniwp.zip"

Write-Host "Creating OmniWP Production Release Package..." -ForegroundColor Cyan

# Clean dist dir
if (Test-Path $DistDir) {
    Remove-Item $DistDir -Recurse -Force
}
New-Item -ItemType Directory -Path $TempDir -Force | Out-Null

# Excluded folders and files
$Excludes = @(
    "tests",
    "scripts",
    "bin",
    "docs",
    ".git",
    ".github",
    ".gemini",
    ".agents",
    "vendor",
    "node_modules",
    "dist",
    ".phpcs-cache",
    "phpcs.xml",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "CLAUDE.md",
    "TODO.md"
)

# Copy runtime files
Get-ChildItem -Path $PluginDir | Where-Object {
    $name = $_.Name
    if ($Excludes -contains $name) {
        return $false
    }
    return $true
} | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $TempDir -Recurse -Force
}

# Compress to zip
Compress-Archive -Path $TempDir -DestinationPath $ZipFile -Force
Remove-Item $TempDir -Recurse -Force

Write-Host "Success! Production package created at: $ZipFile" -ForegroundColor Green

param(
    [string] $Version = "0.1.0",
    [string] $PhpPath = "C:\tools\php85\php.exe"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$dist = Join-Path $root "dist"
$stage = Join-Path $dist "woo-logistics-plugin"
$zipPath = Join-Path $dist "woo-logistics-plugin-$Version.zip"

if (-not (Test-Path -LiteralPath $dist)) {
    New-Item -ItemType Directory -Path $dist | Out-Null
}

if (Test-Path -LiteralPath $stage) {
    Remove-Item -LiteralPath $stage -Recurse -Force
}

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

$env:Path = "C:\tools\php85;C:\ProgramData\ComposerSetup\bin;" + $env:Path
Push-Location $root
try {
    composer install --no-dev --prefer-source --optimize-autoloader
} finally {
    Pop-Location
}

New-Item -ItemType Directory -Path $stage | Out-Null

$items = @(
    "assets",
    "includes",
    "vendor",
    "readme.txt",
    "uninstall.php",
    "woo-logistics-plugin.php"
)

foreach ($item in $items) {
    $source = Join-Path $root $item
    $target = Join-Path $stage $item
    if (Test-Path -LiteralPath $source -PathType Container) {
        Copy-Item -LiteralPath $source -Destination $target -Recurse
    } else {
        Copy-Item -LiteralPath $source -Destination $target
    }
}

Compress-Archive -LiteralPath $stage -DestinationPath $zipPath
Write-Output "Built $zipPath"

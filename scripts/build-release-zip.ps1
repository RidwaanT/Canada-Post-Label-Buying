param(
    [string] $Version = "0.1.18",
    [string] $PackageSlug = "woo-logistics-plugin-2",
    [string] $PhpPath = "C:\tools\php85\php.exe"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$dist = Join-Path $root "dist"
$stage = Join-Path $dist "woo-logistics-plugin"
$zipPath = Join-Path $dist "woo-logistics-plugin-$Version.zip"
$canonicalZipPath = Join-Path $dist "woo-logistics-plugin.zip"

function New-ZipFromDirectory {
    param(
        [Parameter(Mandatory = $true)]
        [string] $SourceDirectory,
        [Parameter(Mandatory = $true)]
        [string] $DestinationZip,
        [Parameter(Mandatory = $false)]
        [string] $EntryPrefix = ""
    )

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $sourceFullPath = [System.IO.Path]::GetFullPath($SourceDirectory)
    $zip = [System.IO.Compression.ZipFile]::Open($DestinationZip, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $sourcePrefix = $sourceFullPath.TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
        Get-ChildItem -LiteralPath $sourceFullPath -File -Recurse | ForEach-Object {
            $fileFullPath = [System.IO.Path]::GetFullPath($_.FullName)
            $relativePath = $fileFullPath.Substring($sourcePrefix.Length)
            $segments = $relativePath -split "[\\/]"
            if ($segments -contains ".git" -or $segments -contains ".github" -or $segments -contains "tests" -or $segments -contains "local-tests" -or $segments -contains "scratches") {
                return
            }
            $entryName = ($relativePath -replace "\\", "/")
            if ($EntryPrefix -ne "") {
                $entryName = ($EntryPrefix.TrimEnd("/") + "/" + $entryName)
            }
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entryName) | Out-Null
        }
    } finally {
        $zip.Dispose()
    }
}

if (-not (Test-Path -LiteralPath $dist)) {
    New-Item -ItemType Directory -Path $dist | Out-Null
}

if (Test-Path -LiteralPath $stage) {
    Remove-Item -LiteralPath $stage -Recurse -Force
}

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

if (Test-Path -LiteralPath $canonicalZipPath) {
    Remove-Item -LiteralPath $canonicalZipPath -Force
}

$env:Path = "C:\tools\php85;C:\ProgramData\ComposerSetup\bin;" + $env:Path
Push-Location $root
try {
    composer install --no-dev --prefer-dist --optimize-autoloader
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

New-ZipFromDirectory -SourceDirectory $stage -DestinationZip $zipPath -EntryPrefix $PackageSlug
New-ZipFromDirectory -SourceDirectory $stage -DestinationZip $canonicalZipPath -EntryPrefix $PackageSlug
Write-Output "Built $zipPath"
Write-Output "Built $canonicalZipPath"

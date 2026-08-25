$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$RuntimeRoot = Join-Path $ProjectRoot "runtime"
$PhpRoot = Join-Path $RuntimeRoot "php"
$PhpExe = Join-Path $PhpRoot "php.exe"

$PhpUrl = "https://www.php.net/~windows/releases/php-8.4.24-nts-Win32-vs17-x64.zip"
$VcUrl  = "https://aka.ms/vs/17/release/vc_redist.x64.exe"

New-Item -ItemType Directory -Path $RuntimeRoot -Force | Out-Null

function Write-Step([string]$Text) {
    Write-Host ""
    Write-Host "============================================================"
    Write-Host $Text
    Write-Host "============================================================"
}

if (-not (Test-Path $PhpExe)) {
    Write-Step "Preparing built-in PHP runtime - first time only"
    $zip = Join-Path $RuntimeRoot "php-runtime.zip"

    try {
        Invoke-WebRequest -Uri $PhpUrl -OutFile $zip -UseBasicParsing
    } catch {
        Write-Host "Unable to download PHP runtime."
        Write-Host "Internet is required only for this first preparation."
        throw
    }

    if (Test-Path $PhpRoot) {
        Remove-Item $PhpRoot -Recurse -Force
    }
    New-Item -ItemType Directory -Path $PhpRoot -Force | Out-Null
    Expand-Archive -Path $zip -DestinationPath $PhpRoot -Force
    Remove-Item $zip -Force
}

$Ini = Join-Path $PhpRoot "php.ini"
$IniText = @"
[PHP]
engine=On
short_open_tag=Off
precision=14
output_buffering=4096
expose_php=Off
max_execution_time=120
max_input_time=120
memory_limit=256M
display_errors=Off
display_startup_errors=Off
log_errors=On
error_log="$($ProjectRoot.Replace('\','/'))/storage/logs/php-error.log"
post_max_size=32M
upload_max_filesize=25M
date.timezone=Asia/Karachi

extension_dir="ext"
extension=mysqli
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=curl
extension=fileinfo

[Session]
session.use_strict_mode=1
session.use_only_cookies=1
session.cookie_httponly=1
session.cookie_samesite=Lax
"@
Set-Content -Path $Ini -Value $IniText -Encoding ASCII

# Test PHP. If VC++ runtime is missing, install it once.
$phpOk = $false
try {
    & $PhpExe -v *> $null
    if ($LASTEXITCODE -eq 0) { $phpOk = $true }
} catch {}

if (-not $phpOk) {
    Write-Step "Installing Microsoft Visual C++ Runtime - one time"
    $vc = Join-Path $RuntimeRoot "vc_redist.x64.exe"
    Invoke-WebRequest -Uri $VcUrl -OutFile $vc -UseBasicParsing
    Start-Process -FilePath $vc -ArgumentList "/install","/quiet","/norestart" -Wait -Verb RunAs
    Start-Sleep -Seconds 2

    try {
        & $PhpExe -v
        if ($LASTEXITCODE -ne 0) { throw "PHP runtime could not start." }
    } catch {
        Write-Host ""
        Write-Host "PHP runtime could not start even after VC++ installation."
        throw
    }
}

Write-Host ""
Write-Host "PHP runtime is ready."
Write-Host $PhpExe

# build: V17.1 build 2026-08-25

# ============================================================
# resolve_php.ps1 - PRIVATE PHP runtime for this package only.
#
# Important: any PHP already installed on the PC (XAMPP, WAMP, Laragon,
# MySQL Workbench bundles, etc.) is IGNORED on purpose. This package uses
# its own PHP with its own php.ini so nothing can conflict.
#
# If vendor\php.zip ships with the package, no download is needed.
#
# Writes the resolved php.exe path to stdout as the last line.
# ============================================================
param([string]$Root = (Split-Path -Parent $PSScriptRoot))
$ErrorActionPreference = 'Stop'
$ProgressPreference    = 'SilentlyContinue'
. (Join-Path $PSScriptRoot 'download_helper.ps1')

$PrivateRoot = Join-Path $Root 'runtime\php'
$IniPath     = Join-Path $PrivateRoot 'php.ini'
$VendorZip   = Join-Path $Root 'vendor\php.zip'

function Say($m,$c='Gray'){ Write-Host "      $m" -ForegroundColor $c }

function Get-PrivatePhp {
  if (-not (Test-Path $PrivateRoot)) { return $null }
  $exe = Get-ChildItem -Path $PrivateRoot -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue |
         Select-Object -First 1
  if ($exe) { return $exe.FullName }
  return $null
}

# ---------- 1) obtain PHP (vendor copy first, else download) ----------
$php = Get-PrivatePhp
if (-not $php) {
  New-Item -ItemType Directory -Force -Path $PrivateRoot | Out-Null
  $zip = $null

  if (Test-Path $VendorZip) {
    Say 'Bundled PHP runtime found (no download needed).' 'Green'
    $zip = $VendorZip
  } else {
    $zip = Join-Path $env:TEMP 'smartpos-php.zip'
    $urls = @(
      'https://windows.php.net/downloads/releases/php-8.2.29-nts-Win32-vs16-x64.zip',
      'https://downloads.php.net/~windows/releases/php-8.2.29-nts-Win32-vs16-x64.zip',
      'https://downloads.php.net/~windows/releases/latest/php-8.2-nts-Win32-vs16-x64-latest.zip'
    )
    $ok = $false
    foreach ($u in $urls) {
      try { Get-FileWithProgress -Url $u -Destination $zip -Label 'PHP runtime'; $ok = $true; break }
      catch { Say 'Source unavailable, trying next...' 'DarkYellow' }
    }
    if (-not $ok) {
      Say 'PHP runtime could not be downloaded. Check your internet' 'Red'
      Say 'connection, or place vendor\php.zip inside this package.' 'Red'
      exit 1
    }
  }

  Say 'Extracting PHP runtime...'
  Expand-Archive -Path $zip -DestinationPath $PrivateRoot -Force
  if ($zip -ne $VendorZip) { Remove-Item $zip -ErrorAction SilentlyContinue }

  $php = Get-PrivatePhp
  if (-not $php) { Say 'PHP was extracted but php.exe was not found.' 'Red'; exit 1 }
}

# ---------- 2) php.ini (always rewritten - keeps us independent) ----------
$phpDir = Split-Path $php
$extDir = Join-Path $phpDir 'ext'
@"
; SmartPOS private PHP configuration - do not edit.
extension_dir="$($extDir -replace '\\','/')"
extension=openssl
extension=mbstring
extension=pdo_mysql
extension=mysqli
extension=curl
extension=fileinfo
extension=zip
extension=gd
memory_limit=512M
max_execution_time=0
date.timezone=Asia/Karachi
display_errors=Off
log_errors=On
"@ | Set-Content -Path $IniPath -Encoding ASCII
# also drop a copy next to php.exe so any invocation picks it up
if ($IniPath -ne (Join-Path $phpDir 'php.ini')) {
  Copy-Item $IniPath (Join-Path $phpDir 'php.ini') -Force
}

# ---------- 3) verify required extensions ----------
$need = @('openssl','mbstring','pdo_mysql','zlib')
$loaded = & $php -c "$IniPath" -r "echo implode(',', get_loaded_extensions());" 2>&1
$missing = @()
foreach ($n in $need) { if ("$loaded" -notmatch "(?i)\b$n\b") { $missing += $n } }
if ($missing.Count -gt 0) {
  Say ("Required PHP extensions missing: " + ($missing -join ', ')) 'Red'
  Say 'Delete the runtime\php folder and run the setup again.' 'Red'
  exit 1
}

$ver = & $php -c "$IniPath" -r "echo PHP_VERSION;" 2>&1
Say "PHP $ver ready (private to this package)." 'Green'
Write-Output $php
exit 0

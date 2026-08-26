# ============================================================
# resolve_mariadb.ps1 - PORTABLE database for the offline package.
#
# Nothing is installed on Windows. The database lives inside this
# folder (runtime\mariadb), keeps its data in data\mysql, and only
# listens on 127.0.0.1.
#
# Any MySQL/MariaDB already installed on the PC (XAMPP, WAMP, Workbench,
# MySQL Server service) is IGNORED on purpose - this package runs its own
# server on port 3307 with its own data folder.
#
# If vendor\mariadb.zip ships with the package, no internet is needed.
# ============================================================
param(
  [string]$Root = (Split-Path -Parent $PSScriptRoot),
  [int]$Port = 3307,
  [switch]$StopServer
)
$ErrorActionPreference = 'Stop'
$ProgressPreference    = 'SilentlyContinue'
. (Join-Path $PSScriptRoot 'download_helper.ps1')

$RuntimeDir = Join-Path $Root 'runtime\mariadb'
$DataDir    = Join-Path $Root 'data\mysql'
$VendorZip  = Join-Path $Root 'vendor\mariadb.zip'
$PidFile    = Join-Path $Root 'data\mariadb.pid'
$PortFile   = Join-Path $Root 'data\mariadb.port'

function Say($m,$c='Gray'){ Write-Host "      $m" -ForegroundColor $c }
function Dots($m){ Write-Host "      $m" -NoNewline -ForegroundColor Gray }

function Get-MysqldPath {
  if (-not (Test-Path $RuntimeDir)) { return $null }
  $exe = Get-ChildItem -Path $RuntimeDir -Filter 'mysqld.exe' -Recurse -ErrorAction SilentlyContinue |
         Select-Object -First 1
  if ($exe) { return $exe.FullName }
  return $null
}

# ---------- STOP ----------
if ($StopServer) {
  $mysqld = Get-MysqldPath
  if ($mysqld) {
    $admin = Join-Path (Split-Path $mysqld) 'mysqladmin.exe'
    if (Test-Path $admin) {
      & $admin --protocol=tcp --host=127.0.0.1 --port=$Port -u root shutdown 2>$null | Out-Null
    }
  }
  if (Test-Path $PidFile) {
    $procId = Get-Content $PidFile -ErrorAction SilentlyContinue
    if ($procId) { Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue }
    Remove-Item $PidFile -ErrorAction SilentlyContinue
  }
  Say 'Local database stopped.' 'Yellow'
  exit 0
}

# ---------- 1) EXTRACT ----------
$mysqld = Get-MysqldPath
if (-not $mysqld) {
  New-Item -ItemType Directory -Force -Path $RuntimeDir | Out-Null
  $zip = $null

  if (Test-Path $VendorZip) {
    Say 'Bundled database found (no internet required).' 'Green'
    $zip = $VendorZip
  } else {
    $zip = Join-Path $env:TEMP 'db-portable.zip'
    $urls = @(
      'https://archive.mariadb.org/mariadb-10.11.8/winx64-packages/mariadb-10.11.8-winx64.zip',
      'https://downloads.mariadb.com/MariaDB/mariadb-10.11.8/winx64-packages/mariadb-10.11.8-winx64.zip'
    )
    $ok = $false
    foreach ($u in $urls) {
      try { Get-FileWithProgress -Url $u -Destination $zip -Label 'Database'; $ok = $true; break }
      catch { Say 'Download source unavailable, trying next...' 'DarkYellow' }
    }
    if (-not $ok) {
      Say 'Database download failed. Check your internet connection,' 'Red'
      Say 'or place vendor\mariadb.zip next to this package.' 'Red'
      exit 1
    }
  }

  Dots 'Extracting'
  $job = Start-Job -ScriptBlock {
    param($z,$d)
    $ProgressPreference = 'SilentlyContinue'
    Expand-Archive -Path $z -DestinationPath $d -Force
  } -ArgumentList $zip, $RuntimeDir
  while ($job.State -eq 'Running') { Write-Host '.' -NoNewline -ForegroundColor Gray; Start-Sleep -Seconds 2 }
  Receive-Job $job -ErrorAction SilentlyContinue | Out-Null
  Remove-Job $job -Force -ErrorAction SilentlyContinue
  Write-Host ' done.' -ForegroundColor Green

  if ($zip -ne $VendorZip) { Remove-Item $zip -ErrorAction SilentlyContinue }
  $mysqld = Get-MysqldPath
  if (-not $mysqld) { Say 'Database files could not be extracted.' 'Red'; exit 1 }
}
$BinDir = Split-Path $mysqld

# ---------- 2) INITIALIZE ----------
if (-not (Test-Path (Join-Path $DataDir 'mysql'))) {
  Say 'Creating the local database for the first time...'
  New-Item -ItemType Directory -Force -Path $DataDir | Out-Null
  $install = Join-Path $BinDir 'mariadb-install-db.exe'
  if (-not (Test-Path $install)) { $install = Join-Path $BinDir 'mysql_install_db.exe' }
  if (Test-Path $install) { & $install "--datadir=$DataDir" 2>&1 | Out-Null }
  else { & $mysqld "--initialize-insecure" "--datadir=$DataDir" 2>&1 | Out-Null }
  if (-not (Test-Path (Join-Path $DataDir 'mysql'))) { Say 'Database could not be initialized.' 'Red'; exit 1 }
}

# ---------- 3) CONFIG (localhost only) ----------
$IniPath = Join-Path $Root 'runtime\my.ini'
@"
[mysqld]
datadir=$($DataDir -replace '\\','/')
port=$Port
bind-address=127.0.0.1
skip-name-resolve
max_connections=60
innodb_buffer_pool_size=256M
innodb_flush_log_at_trx_commit=2
sql_mode=STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
"@ | Set-Content -Path $IniPath -Encoding ASCII

# ---------- 4) START ----------
$alive = $false
try { $c = New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1', $Port); $alive = $true; $c.Close() } catch { $alive = $false }

if (-not $alive) {
  Dots "Starting local database on port $Port"
  $p = Start-Process -FilePath $mysqld -ArgumentList "--defaults-file=`"$IniPath`"" -WindowStyle Hidden -PassThru
  $p.Id | Set-Content $PidFile -Encoding ASCII
  for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Milliseconds 500
    if ($i % 4 -eq 0) { Write-Host '.' -NoNewline -ForegroundColor Gray }
    try { $c = New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1', $Port); $c.Close(); $alive = $true; break } catch {}
  }
  Write-Host ''
  if (-not $alive) { Say 'The local database did not start.' 'Red'; exit 1 }
}
$Port | Set-Content $PortFile -Encoding ASCII

# ---------- 5) APP DATABASE ----------
$mysqlExe = Join-Path $BinDir 'mysql.exe'
if (-not (Test-Path $mysqlExe)) { $mysqlExe = Join-Path $BinDir 'mariadb.exe' }
& $mysqlExe --protocol=tcp --host=127.0.0.1 --port=$Port -u root `
   -e "CREATE DATABASE IF NOT EXISTS aio_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1 | Out-Null

Say "Local database ready (127.0.0.1:$Port)." 'Green'
exit 0

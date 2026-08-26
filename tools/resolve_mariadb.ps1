# ============================================================
# resolve_mariadb.ps1 — PORTABLE MariaDB for the offline package.
#
# Maqsad: customer ke PC par kuch INSTALL na ho. MariaDB ka portable
# ZIP package ke andar (runtime\mariadb) rehta hai, apna data directory
# (data\mysql) rakhta hai, aur sirf 127.0.0.1 par sunta hai.
#
# Agar package ke saath MariaDB pehle se bundled hai (vendor\mariadb.zip)
# to internet ki bhi zaroorat nahi.
# ============================================================
param(
  [string]$Root = (Split-Path -Parent $PSScriptRoot),
  [int]$Port = 3307,           # 3306 se alag: kisi mojooda MySQL se takkar na ho
  [switch]$StopServer
)
$ErrorActionPreference = 'Stop'

$RuntimeDir = Join-Path $Root 'runtime\mariadb'
$DataDir    = Join-Path $Root 'data\mysql'
$VendorZip  = Join-Path $Root 'vendor\mariadb.zip'
$PidFile    = Join-Path $Root 'data\mariadb.pid'
$PortFile   = Join-Path $Root 'data\mariadb.port'

function Say($m,$c='Gray'){ Write-Host "  $m" -ForegroundColor $c }

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
  Say 'Portable MariaDB rok diya gaya.' 'Yellow'
  exit 0
}

# ---------- 1) EXTRACT (agar pehle se nahi) ----------
$mysqld = Get-MysqldPath
if (-not $mysqld) {
  New-Item -ItemType Directory -Force -Path $RuntimeDir | Out-Null

  $zip = $null
  if (Test-Path $VendorZip) {
    Say 'Bundled MariaDB mil gaya (internet ki zaroorat nahi).' 'Green'
    $zip = $VendorZip
  } else {
    Say 'MariaDB portable download ho raha hai (ek dafa, ~90MB)...' 'Cyan'
    $zip = Join-Path $env:TEMP 'mariadb-portable.zip'
    $urls = @(
      'https://archive.mariadb.org/mariadb-10.11.8/winx64-packages/mariadb-10.11.8-winx64.zip',
      'https://downloads.mariadb.com/MariaDB/mariadb-10.11.8/winx64-packages/mariadb-10.11.8-winx64.zip'
    )
    $ok = $false
    foreach ($u in $urls) {
      try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -Uri $u -OutFile $zip -UseBasicParsing -TimeoutSec 900
        $ok = $true; break
      } catch { Say "Download nakaam: $u" 'DarkYellow' }
    }
    if (-not $ok) {
      throw "MariaDB download nahi ho saka. Internet check karein, ya vendor\mariadb.zip package ke saath rakhein."
    }
  }

  Say 'Extract ho raha hai...' 'Cyan'
  Expand-Archive -Path $zip -DestinationPath $RuntimeDir -Force
  if ($zip -ne $VendorZip) { Remove-Item $zip -ErrorAction SilentlyContinue }

  $mysqld = Get-MysqldPath
  if (-not $mysqld) { throw 'MariaDB extract to hua magar mysqld.exe nahi mila.' }
}
$BinDir = Split-Path $mysqld

# ---------- 2) INITIALIZE DATA DIR (pehli dafa) ----------
if (-not (Test-Path (Join-Path $DataDir 'mysql'))) {
  Say 'Local database pehli dafa banayi ja rahi hai...' 'Cyan'
  New-Item -ItemType Directory -Force -Path $DataDir | Out-Null
  $install = Join-Path $BinDir 'mariadb-install-db.exe'
  if (-not (Test-Path $install)) { $install = Join-Path $BinDir 'mysql_install_db.exe' }
  if (Test-Path $install) {
    & $install "--datadir=$DataDir" 2>&1 | Out-Null
  } else {
    & $mysqld "--initialize-insecure" "--datadir=$DataDir" 2>&1 | Out-Null
  }
  if (-not (Test-Path (Join-Path $DataDir 'mysql'))) { throw 'Database initialize nahi ho saki.' }
}

# ---------- 3) my.ini (localhost-only, koi network exposure nahi) ----------
$IniPath = Join-Path $Root 'runtime\my.ini'
@"
[mysqld]
datadir=$($DataDir -replace '\\','/')
port=$Port
bind-address=127.0.0.1
skip-name-resolve
skip-networking=0
max_connections=60
innodb_buffer_pool_size=256M
innodb_flush_log_at_trx_commit=2
sql_mode=STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
"@ | Set-Content -Path $IniPath -Encoding ASCII

# ---------- 4) START (agar chal nahi raha) ----------
$alive = $false
try {
  $c = New-Object Net.Sockets.TcpClient
  $c.Connect('127.0.0.1', $Port); $alive = $true; $c.Close()
} catch { $alive = $false }

if (-not $alive) {
  Say "Portable MariaDB start ho raha hai (port $Port)..." 'Cyan'
  $p = Start-Process -FilePath $mysqld -ArgumentList "--defaults-file=`"$IniPath`"" `
        -WindowStyle Hidden -PassThru
  $p.Id | Set-Content $PidFile -Encoding ASCII
  for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Milliseconds 500
    try { $c = New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1', $Port); $c.Close(); $alive = $true; break } catch {}
  }
  if (-not $alive) { throw "MariaDB start nahi hua. runtime\my.ini aur data\mysql check karein." }
}
$Port | Set-Content $PortFile -Encoding ASCII

# ---------- 5) APP DATABASE ----------
$mysqlExe = Join-Path $BinDir 'mysql.exe'
if (-not (Test-Path $mysqlExe)) { $mysqlExe = Join-Path $BinDir 'mariadb.exe' }
& $mysqlExe --protocol=tcp --host=127.0.0.1 --port=$Port -u root `
   -e "CREATE DATABASE IF NOT EXISTS aio_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>&1 | Out-Null

Say "Portable MariaDB tayyar (127.0.0.1:$Port, database: aio_local)" 'Green'
exit 0

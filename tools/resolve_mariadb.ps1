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

    # V92 -- "100% complete" ka matlab yeh NAHI ke file waqai ZIP hai.
    # Server ne HTML error page bhej diya ho, ya connection beech mein
    # toot gaya ho, to file to ban jati hai magar khulti nahi. Pehle
    # yeh baat extract ke waqt ajeeb ghalti ban kar samne aati thi.
    $bad = $false
    if (-not (Test-Path -LiteralPath $zip)) { $bad = $true }
    else {
      $len = (Get-Item -LiteralPath $zip).Length
      if ($len -lt 20MB) { $bad = $true }
      else {
        try {
          $fs = [System.IO.File]::OpenRead($zip)
          $sig = New-Object byte[] 2
          $fs.Read($sig, 0, 2) | Out-Null
          $fs.Close()
          if ($sig[0] -ne 0x50 -or $sig[1] -ne 0x4B) { $bad = $true }   # 'PK'
        } catch { $bad = $true }
      }
    }
    if ($bad) {
      Say 'The downloaded database file is not usable (incomplete or blocked).' 'Red'
      Say 'This usually means the internet dropped, or a firewall replaced' 'Red'
      Say 'the download. Try again, or place vendor\mariadb.zip next to' 'Red'
      Say 'this package and run the setup again.' 'Red'
      if (Test-Path -LiteralPath $zip) {
        try { Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue } catch { }
      }
      exit 1
    }
  }

  # V92 -- EXTRACT.
  #
  # Pehle yahan `Receive-Job ... | Out-Null` tha, yani Expand-Archive ki
  # ASLI GHALTI phenk di jati thi. Nateeja: " done." chhap jata tha aur
  # phir "Database files could not be extracted" -- bina kisi wajah ke.
  # Customer ke paas koi rasta nahi bachta tha.
  #
  # Ab: ghalti pakri jati hai, aur agar Expand-Archive nakaam ho to
  # .NET ka ZipFile aazmaya jata hai. Purane Windows PowerShell 5.1 par
  # Expand-Archive bare zip aur lambe raston par nakaam ho jata hai;
  # .NET wala tareeqa wahan bhi chal jata hai.
  Dots 'Extracting'
  $zipErr = $null

  $job = Start-Job -ScriptBlock {
    param($z, $d)
    $ProgressPreference = 'SilentlyContinue'
    try {
      Expand-Archive -Path $z -DestinationPath $d -Force -ErrorAction Stop
      return 'OK'
    } catch {
      return 'ERR: ' + $_.Exception.Message
    }
  } -ArgumentList $zip, $RuntimeDir

  while ($job.State -eq 'Running') { Write-Host '.' -NoNewline -ForegroundColor Gray; Start-Sleep -Seconds 2 }
  $res = Receive-Job $job -ErrorAction SilentlyContinue
  Remove-Job $job -Force -ErrorAction SilentlyContinue
  if ($res -is [array]) { $res = $res[-1] }
  if ("$res" -like 'ERR:*') { $zipErr = "$res".Substring(5).Trim() }

  # Doosra tareeqa -- .NET seedha.
  if ($zipErr -or -not (Get-MysqldPath)) {
    Write-Host ''
    Say '      First method did not work, trying another...' 'DarkYellow'
    try {
      Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction Stop
      [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $RuntimeDir)
      $zipErr = $null
    } catch {
      # Files pehle se mojood hon to yeh "already exists" deta hai --
      # us soorat mein pehla tareeqa waqai chal chuka hai.
      if ("$($_.Exception.Message)" -notlike '*already exists*') {
        $zipErr = $_.Exception.Message
      } else {
        $zipErr = $null
      }
    }
  }

  Write-Host ' done.' -ForegroundColor Green
  if ($zip -ne $VendorZip) {
    if ($zip -and (Test-Path -LiteralPath $zip)) {
      try { Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue } catch { }
    }
  }

  $mysqld = Get-MysqldPath
  if (-not $mysqld) {
    Say 'Database files could not be extracted.' 'Red'
    if ($zipErr) { Say ("Reason: " + $zipErr) 'Red' }

    # Customer ko batao ke waqai hua kya -- khali "nakaam" bekaar hai.
    $found = Get-ChildItem -Path $RuntimeDir -Recurse -ErrorAction SilentlyContinue |
             Select-Object -First 5
    if ($found) {
      Say 'These files did come out, but mysqld.exe is not among them:' 'DarkYellow'
      foreach ($f in $found) { Say ('  ' + $f.Name) 'DarkGray' }
    } else {
      Say 'Nothing came out of the archive at all.' 'DarkYellow'
    }

    $len = (Join-Path $RuntimeDir 'mariadb-10.11.8-winx64\bin\mysqld.exe').Length
    if ($len -gt 250) {
      Say '' 'Gray'
      Say 'The folder path is very long, and Windows cannot handle paths' 'Yellow'
      Say 'over 260 characters. Move this package somewhere shorter,' 'Yellow'
      Say 'for example C:\SmartPOS, and run the setup again.' 'Yellow'
    }
    exit 1
  }
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
  # quoted argument string - folder names with spaces/brackets safe
  $dbArgs = '--defaults-file="{0}"' -f $IniPath
  $p = Start-Process -FilePath $mysqld -ArgumentList $dbArgs -WindowStyle Hidden -PassThru
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

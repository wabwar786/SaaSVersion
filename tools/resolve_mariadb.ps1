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

# V142 - see native_helper.ps1. mariadb-install-db.exe and mysql.exe both
# write normal progress to stderr. With 'Stop' set above, capturing that
# with `2>&1` made it a TERMINATING error in Windows PowerShell 5.1, so a
# perfectly healthy first-time database init could kill this script.
# ------------------------------------------------------------
# V143 - helper ko load karo, aur agar woh file maujood na ho to khud
# bana lo. Yeh guard is liye hai ke V142 ke package mein `start_offline.ps1`
# to nayi chali gayi magar `native_helper.ps1` reh gayi (packager ki list
# mein naam daalna bhool gaya tha), aur software khulna hi band ho gaya:
#
#     The term 'D:\MOB\tools\native_helper.ps1' is not recognized...
#
# Ek missing file se poora POS band ho jana qabil-e-qubool nahi. Ab adhoora
# update bhi aa jaye to yeh script apne paanv par khari rehti hai.
# ------------------------------------------------------------
$__nh = Join-Path $PSScriptRoot 'native_helper.ps1'
if (Test-Path $__nh) { . $__nh }
if (-not (Get-Command Invoke-Native -ErrorAction SilentlyContinue)) {
  function Invoke-Native {
    param([Parameter(Mandatory = $true)][string] $Exe, [string[]] $Arguments = @())
    $ErrorActionPreference = 'Continue'
    if (Test-Path variable:PSNativeCommandUseErrorActionPreference) {
      $PSNativeCommandUseErrorActionPreference = $false
    }
    $global:NativeExitCode = 0
    $text = ''
    try {
      $raw = & $Exe @Arguments 2>&1
      $global:NativeExitCode = if ($null -ne $LASTEXITCODE) { $LASTEXITCODE } else { 0 }
      $lines = @()
      foreach ($item in $raw) {
        if ($null -eq $item) { continue }
        if ($item -is [System.Management.Automation.ErrorRecord]) { $lines += $item.ToString() }
        else { $lines += [string]$item }
      }
      $text = ($lines -join "`r`n")
    } catch { $global:NativeExitCode = 1; $text = ($_ | Out-String) }
    return $text
  }
  function Invoke-NativeScript {
    param([Parameter(Mandatory = $true)][string] $ScriptPath, [string[]] $Arguments = @())
    $exe = 'powershell.exe'
    if (-not (Get-Command $exe -ErrorAction SilentlyContinue)) {
      $exe = (Get-Process -Id $PID).Path
      if (-not $exe) { $exe = 'pwsh' }
    }
    return (Invoke-Native -Exe $exe -Arguments (@('-NoProfile','-ExecutionPolicy','Bypass','-File',$ScriptPath) + $Arguments))
  }
}

$RuntimeDir = Join-Path $Root 'runtime\mariadb'
$VendorZip  = Join-Path $Root 'vendor\mariadb.zip'
$PidFile    = Join-Path $Root 'data\mariadb.pid'
$PortFile   = Join-Path $Root 'data\mariadb.port'

function Say($m,$c='Gray'){ Write-Host "      $m" -ForegroundColor $c }
function Dots($m){ Write-Host "      $m" -NoNewline -ForegroundColor Gray }

# ============================================================
# V155 - DATABASE AB SOFTWARE KE FOLDER KA QAIDI NAHI.
#
# JO HUA (asli waqia, ek customer ke sath):
#   Pehle software D:\MOB mein install hua. Teen hafte billing hui,
#   data D:\MOB\data\mysql mein jama raha.
#   Baad mein wahi software D:\MOB1 mein install kar liya gaya.
#   $DataDir seedha $Root se banta tha, yani D:\MOB1\data\mysql -
#   ek bilkul NAYA, KHALI datadir. Script ne chupchaap wo bana diya,
#   `aio_local` bhi bana diya (khali), aur software chal para.
#   Nateeja: php.exe crash aur "Login service error", kyunke database
#   mein ek bhi table nahi tha. Customer ko lagta raha ke us ki
#   30,000 ki sale MIT GAYI.
#
# Data gaya nahi tha - D:\MOB mein salamat para tha. Magar yeh baat
# customer ko kaise pata chalti?
#
# AB TARTEEB YEH HAI:
#   1. Is folder ke andar pehle se database hai? -> wahi chalao.
#      (Purani installs jahan hain wahin kaam karti rahen - chalte
#       POS ka datadir khiskana sab se khatarnaak kaam hota.)
#   2. Nahi? To nishani (ProgramData\Wabwar\SmartPOS\datadir.txt)
#      dekho - is PC par aakhri dafa database kahan tha.
#   3. Nahi? To mushtarka jagah: %ProgramData%\Wabwar\SmartPOS\data
#   4. Kuch bhi nahi? Nayi install hai - mushtarka jagah par banao.
#
# Is tarteeb ka faida: ab software kisi bhi folder mein dobara install
# ho, data wahi rehta hai. Folder ka naam badalna, package naye folder
# mein nikalna, purana folder rakh kar naya banana - kisi soorat mein
# data gayab nahi hota.
# ============================================================
$SharedBase = Join-Path $env:ProgramData 'Wabwar\SmartPOS'
$SharedData = Join-Path $SharedBase 'data\mysql'
$LocalData  = Join-Path $Root 'data\mysql'
$Marker     = Join-Path $SharedBase 'datadir.txt'
$FreshFlag  = Join-Path $Root 'START_FRESH.txt'

# Datadir "bhara" tab hai jab us ke andar `mysql` (system database)
# mojood ho. Khali folder ya sirf ibdata1 se kuch sabit nahi hota.
function Test-HasDb([string]$p) {
  if (-not $p) { return $false }
  try { return (Test-Path (Join-Path $p 'mysql')) } catch { return $false }
}

$MarkedDir = $null
if (Test-Path $Marker) {
  try { $MarkedDir = (((Get-Content $Marker -Raw) -split "`r?`n")[0]).Trim() } catch { $MarkedDir = $null }
  if (-not $MarkedDir) { $MarkedDir = $null }
}

$DataDir = $null
if (Test-HasDb $LocalData) {
  $DataDir = $LocalData                      # 1 - purani install, jahan hai wahin rahe
} elseif (Test-HasDb $MarkedDir) {
  $DataDir = $MarkedDir                      # 2 - nishani se mila
  Say ("Using the existing database from: " + $DataDir) 'DarkGray'
} elseif (Test-HasDb $SharedData) {
  $DataDir = $SharedData                     # 3 - mushtarka jagah
} else {
  # ----------------------------------------------------------
  # V155 - KHALI DATADIR KA PEHRA.
  #
  # Yahan pohanchne ka matlab: naya database banana parega. Agar
  # nishani kehti hai ke is PC par pehle database THA, magar us
  # jagah ab nahi mil raha, to KHAMOSHI SE naya khali database
  # bana dena sab se bara nuqsan hai - customer ka saara purana
  # data "gayab" nazar aata hai (bilkul wohi jo D:\MOB / D:\MOB1
  # wale sath hua). Ruk jao, aur sach batao.
  # ----------------------------------------------------------
  if ($MarkedDir -and -not (Test-Path $FreshFlag)) {
    Say '' 'Gray'
    Say 'A database already exists for this software on this computer,' 'Red'
    Say 'but it is not where it was last time. Last known location:' 'Red'
    Say ('    ' + $MarkedDir) 'Yellow'
    Say '' 'Gray'
    Say 'Nothing has been changed, and no data has been lost. This usually' 'White'
    Say 'happens when the software folder was renamed, moved, or the package' 'White'
    Say 'was extracted into a different folder.' 'White'
    Say '' 'Gray'
    Say 'What to do:' 'Cyan'
    Say '  1. Find the folder above (it may be on another drive).' 'White'
    Say '  2. Copy the whole "data" folder from there into:' 'White'
    Say ('       ' + (Join-Path $SharedBase 'data')) 'Yellow'
    Say '  3. Run this setup again.' 'White'
    Say '' 'Gray'
    Say 'If you really want to start with a brand new, EMPTY database and' 'DarkYellow'
    Say 'you do not need the old sales, create an empty file named' 'DarkYellow'
    Say ('    ' + $FreshFlag) 'Yellow'
    Say 'and run this setup again.' 'DarkYellow'
    Say '' 'Gray'
    Say 'Need help? Wabwar Software House - +92 342 5095104' 'Cyan'
    exit 1
  }
  $DataDir = $SharedData                     # 4 - nayi install
  if (Test-Path $FreshFlag) {
    try { Remove-Item -LiteralPath $FreshFlag -Force -ErrorAction SilentlyContinue } catch { }
    Say 'Starting with a new, empty database (as requested).' 'DarkYellow'
  }
}

# Mushtarka jagah banao - agar ProgramData par ijazat na mile to
# chupchaap folder ke andar wapas aa jao (software band nahi hona
# chahiye sirf is wajah se).
if ($DataDir -eq $SharedData) {
  try { New-Item -ItemType Directory -Force -Path (Split-Path $SharedData) -ErrorAction Stop | Out-Null }
  catch {
    Say 'Could not use the shared data folder; keeping the data inside this folder.' 'DarkYellow'
    $DataDir = $LocalData
  }
}

# Nishani likho - agli install (kisi bhi folder mein) isay parh kar
# WAHI database dhoond legi.
try {
  New-Item -ItemType Directory -Force -Path $SharedBase -ErrorAction Stop | Out-Null
  Set-Content -Path $Marker -Value $DataDir -Encoding ASCII -ErrorAction Stop
} catch { }

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
  # V95 -- JAGAH PEHLE CHECK KARO, baad mein nahi.
  #
  # Pehle setup 100 MB download karta, phir nikalne ki koshish karta,
  # AUR PHIR batata ke disk bhari hai. Customer ka waqt aur data dono
  # zaya. Ab shuru mein hi saaf batao.
  #
  # MariaDB: zip ~100 MB, khulne ke baad ~800 MB. 1.2 GB maangte hain
  # taake database banane ke liye bhi kuch bache.
  try {
    $drive = (Get-Item $Root -ErrorAction SilentlyContinue).PSDrive
    if (-not $drive) { $drive = Get-PSDrive -Name ((Get-Location).Path.Substring(0,1)) -ErrorAction SilentlyContinue }
    if ($drive -and $drive.Free) {
      $freeGB = [math]::Round($drive.Free / 1GB, 2)
      if ($drive.Free -lt 1.2GB) {
        Say '' 'Gray'
        Say ("Not enough space on drive " + $drive.Name + ": -- only " + $freeGB + " GB free.") 'Red'
        Say 'The database needs about 1.2 GB to install.' 'Red'
        Say '' 'Gray'
        Say 'What usually frees the most space:' 'Yellow'
        Say '  1. Delete old SmartPOS packages from your Downloads folder' 'White'
        Say '  2. Empty the Recycle Bin' 'White'
        Say '  3. Clear temporary files (Windows key + R, type %temp%, delete all)' 'White'
        Say '' 'Gray'
        Say 'Or move this package to another drive that has more space,' 'Yellow'
        Say 'and run INSTALL_OFFLINE.bat from there.' 'Yellow'
        exit 1
      }
      Say ("Free space on " + $drive.Name + ": " + $freeGB + " GB -- enough.") 'DarkGray'
    }
  } catch { }

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

    # V95 -- disk bhar jane par aadhi nikli hui files ULTA nuqsan deti
    # hain: wo jagah bhi ghere rehti hain aur agli koshish par
    # "already exists" de kar usay bhi rok deti hain. Saaf kar do.
    if ("$zipErr" -like '*not enough space*' -or "$zipErr" -like '*space on the disk*') {
      Say '' 'Gray'
      Say 'The disk filled up part way through.' 'Yellow'
      Say 'Removing the half-extracted files so they do not waste space...' 'Yellow'
      try { Remove-Item -LiteralPath $RuntimeDir -Recurse -Force -ErrorAction SilentlyContinue } catch { }
      Say '' 'Gray'
      Say 'Free up about 1.2 GB, then run INSTALL_OFFLINE.bat again:' 'Yellow'
      Say '  1. Delete old SmartPOS packages from your Downloads folder' 'White'
      Say '  2. Empty the Recycle Bin' 'White'
      Say '  3. Clear temporary files (Windows key + R, type %temp%, delete all)' 'White'
      exit 1
    }

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
  if (Test-Path $install) { Invoke-Native -Exe $install -Arguments @("--datadir=$DataDir") | Out-Null }
  else { Invoke-Native -Exe $mysqld -Arguments @('--initialize-insecure', "--datadir=$DataDir") | Out-Null }
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
Invoke-Native -Exe $mysqlExe -Arguments @(
  '--protocol=tcp', '--host=127.0.0.1', "--port=$Port", '-u', 'root',
  '-e', 'CREATE DATABASE IF NOT EXISTS aio_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
) | Out-Null

Say "Local database ready (127.0.0.1:$Port)." 'Green'
exit 0

# build: V155 build 2026-10-15

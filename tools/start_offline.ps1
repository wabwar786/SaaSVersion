# ============================================================
# start_offline.ps1 - Starts the offline software:
#   1) local database (portable, port 3307)
#   2) pre-flight check of the application
#   3) private PHP web server (with a real health check)
#   4) opens the browser only after the server responds
#
# Any PHP / MySQL installed on this PC is ignored on purpose.
# ============================================================
$ErrorActionPreference = 'Stop'
$ProgressPreference    = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# V142 - see native_helper.ps1. The application pre-flight below runs
# php.exe and reads its output. With 'Stop' set above, any single stderr
# line from PHP turned the capture into a TERMINATING error on Windows
# PowerShell 5.1 - so instead of the friendly "The application could not
# start:" message, the customer got a PowerShell exception dump and the
# launcher quit. Now the text always comes back as text.
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

function Line { Write-Host ("=" * 60) -ForegroundColor DarkGray }
function Say($m,$c='Gray'){ Write-Host $m -ForegroundColor $c }
function Bad($m){ Write-Host $m -ForegroundColor Red }

# ---------- product / company info ----------
$product='SmartPOS'; $company='Wabwar Software House'; $version='1.0.0'
$phone='+92 342 5095104'; $website='www.wabwar.pk'; $email='info@wabwar.pk'; $expiry=''
$bizName='SmartPOS'; $branch=''
if (Test-Path "$root\runtime\app.info") {
  try {
    $i = Get-Content "$root\runtime\app.info" -Raw | ConvertFrom-Json
    if ($i.name){$bizName=$i.name}; if ($i.branch){$branch=$i.branch}
    if ($i.product){$product=$i.product}; if ($i.company){$company=$i.company}
    if ($i.version){$version=$i.version}; if ($i.phone){$phone=$i.phone}
    if ($i.website){$website=$i.website}; if ($i.email){$email=$i.email}
    if ($i.expiry){$expiry=$i.expiry}
  } catch {}
}

Write-Host ''
Line
Write-Host "  $bizName" -ForegroundColor Green
Line
Write-Host "  Product        : $product" -ForegroundColor White
if ($branch) { Write-Host "  Branch         : $branch" -ForegroundColor White }
Write-Host "  Company        : $company" -ForegroundColor White
if (Test-Path "$root\VERSION") {
  try { $v = (Get-Content "$root\VERSION" -Raw).Trim(); if ($v) { $version = $v } } catch {}
}
Write-Host "  Version        : $version" -ForegroundColor White
Write-Host "  Contact number : $phone"   -ForegroundColor White
Write-Host "  Website        : $website" -ForegroundColor White
Write-Host "  Email          : $email"   -ForegroundColor White
if ($expiry) {
  $expClr = 'White'
  try {
    $d = [datetime]::Parse($expiry)
    $daysLeft = [int]([datetime]$d - (Get-Date)).TotalDays
    if     ($daysLeft -lt 0)  { $expClr = 'Red';        $expiry = "$expiry  (KHATAM HO CHUKI)" }
    elseif ($daysLeft -le 15) { $expClr = 'DarkYellow'; $expiry = "$expiry  ($daysLeft din baqi)" }
  } catch {}
  Write-Host "  Licence expiry : $expiry" -ForegroundColor $expClr
}
Line
Write-Host ''

# ---------- 1) database ----------
Say 'Starting local database...' 'Cyan'
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1"
if ($LASTEXITCODE -ne 0) {
  Bad 'The local database did not start.'
  Bad 'Please run INSTALL_OFFLINE.bat once, then try again.'
  Read-Host 'Press Enter to close'; exit 1
}

# ---------- 2) private PHP ----------
$php = Get-ChildItem -Path (Join-Path $root 'runtime\php') -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue |
       Select-Object -First 1
if (-not $php) {
  Bad 'PHP was not found inside this package.'
  Bad 'Please run INSTALL_OFFLINE.bat first.'
  Read-Host 'Press Enter to close'; exit 1
}
$phpExe = $php.FullName
$phpIni = Join-Path (Split-Path $phpExe) 'php.ini'
if (-not (Test-Path $phpIni)) { $phpIni = Join-Path $root 'runtime\php\php.ini' }

$logDir = Join-Path $root 'storage\logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$outLog = Join-Path $logDir 'server.out.log'
$errLog = Join-Path $logDir 'server.err.log'

# ---------- 3) pre-flight: can the application start at all? ----------
Say 'Checking application...' 'Cyan'
$rootFwd = $root -replace '\\','/'
$check = Invoke-Native -Exe $phpExe -Arguments @('-c', "$phpIni", '-r',
  "require '$rootFwd/runtime/boot.php'; SealedApp::boot('$rootFwd'); echo 'APP_OK';")
if ("$check" -notmatch 'APP_OK') {
  Bad 'The application could not start:'
  "$check".Split("`n") | Select-Object -First 6 | ForEach-Object { Bad "  $_" }
  Bad ''
  Bad 'Tip: delete the runtime\php folder and run INSTALL_OFFLINE.bat again.'
  Read-Host 'Press Enter to close'; exit 1
}

# ---------- 3a) V155: KHALI DATABASE KA PEHRA ----------
#
# JO HUA: ek customer ka `aio_local` mojood tha magar us mein EK BHI
# TABLE NAHI THI (nayi jagah par bana khali datadir - dekho
# resolve_mariadb.ps1 ka V155 wala hissa). Launcher ne phir bhi server
# chala diya. Login ne `users` par query ki aur php.exe gir gaya:
#
#     php.exe - The instruction at 0x... referenced memory at 0x0
#     Login service error
#
# Customer ko is se yeh nazar aaya ke "saara data mit gaya" - halanke
# data doosre folder mein salamat tha. Teen ghante is ki tafteesh mein
# gaye.
#
# AB: server chalane se PEHLE dekha jata hai ke tables hain ya nahi.
# Nahi hain to khamoshi se crash hone ke bajaye setup khud dobara chal
# jata hai. Yeh scripts idempotent hain (`CREATE TABLE IF NOT EXISTS`),
# is liye jo data mojood hai usay koi khatra nahi.
Say 'Checking database tables...' 'Cyan'
$schemaCode = "require '$rootFwd/runtime/boot.php'; SealedApp::boot('$rootFwd'); SealedApp::run('scripts/check_local_schema.php');"
$schema = Invoke-Native -Exe $phpExe -Arguments @('-c', "$phpIni", '-r', $schemaCode)
$schemaExit = $global:NativeExitCode

if ($schemaExit -eq 20 -or $schemaExit -eq 21) {
  Write-Host ''
  if ($schemaExit -eq 20) {
    Say 'This database has no tables yet. Setting it up now...' 'Yellow'
  } else {
    Say 'Some database tables are missing. Repairing now...' 'Yellow'
    Say ("  " + ("$schema" -split "`r?`n" | Where-Object { $_ -match 'SCHEMA_' } | Select-Object -First 1)) 'DarkGray'
  }
  Say 'This runs only once and takes about a minute. Please wait.' 'DarkGray'

  # Wohi fehrist jo INSTALL_OFFLINE.bat chalati hai, usi tarteeb mein.
  $setupScripts = @(
    'install_schema','migrate_platform','migrate_sync','migrate_bridge',
    'migrate_menu_image','migrate_branding','migrate_qr_orders','migrate_devices',
    'migrate_shifts','migrate_sync_columns','migrate_sync_log','migrate_delete_support',
    'migrate_print_rule_default','migrate_fiscal','migrate_security','migrate_modules',
    'migrate_selfservice','migrate_licence_key','seed_roles','migrate_role_scope',
    'seed_platform_modules','migrate_module_ids','migrate_retail','migrate_retail_ops',
    'seed_industry_modules','bootstrap_offline','ensure_default_admin'
  )
  $setupFailed = 0
  foreach ($s in $setupScripts) {
    $c = "require '$rootFwd/runtime/boot.php'; SealedApp::boot('$rootFwd'); SealedApp::run('scripts/$s.php');"
    $o = Invoke-Native -Exe $phpExe -Arguments @('-c', "$phpIni", '-r', $c)
    if ($global:NativeExitCode -ne 0) {
      $setupFailed++
      Say ("  Step failed: " + $s) 'DarkYellow'
      ("$o" -split "`r?`n") | Where-Object { $_ -match '\S' } | Select-Object -First 2 |
        ForEach-Object { Say ("    " + $_) 'DarkGray' }
    }
  }

  # Dobara dekho - ab bhi khali ho to server chalana be-maani hai.
  $schema = Invoke-Native -Exe $phpExe -Arguments @('-c', "$phpIni", '-r', $schemaCode)
  if ($global:NativeExitCode -ne 0) {
    Bad ''
    Bad 'The database could not be set up, so the software will not start.'
    Bad 'Starting it now would only show a login error and crash.'
    Bad ''
    ("$schema" -split "`r?`n") | Where-Object { $_ -match '\S' } | Select-Object -First 3 |
      ForEach-Object { Bad ("  " + $_) }
    Bad ''
    Bad 'Please run INSTALL_OFFLINE.bat once, then start the software again.'
    Bad 'If this keeps happening, contact Wabwar Software House: +92 342 5095104'
    Read-Host 'Press Enter to close'; exit 1
  }
  Say 'Database is ready.' 'Green'
} elseif ($schemaExit -ne 0) {
  # Database se baat hi nahi ho rahi. Server chalane ka koi faida nahi -
  # har safha isi ghalti par ruk jata.
  Bad ''
  Bad 'The software cannot reach its own database.'
  ("$schema" -split "`r?`n") | Where-Object { $_ -match '\S' } | Select-Object -First 3 |
    ForEach-Object { Bad ("  " + $_) }
  Bad ''
  Bad 'Please run INSTALL_OFFLINE.bat once, then start the software again.'
  Read-Host 'Press Enter to close'; exit 1
}

# ---------- 3b) licence ----------
# V149 — `licence_enforce.php` sirf `windows_bootstrap.ps1` (pehli
# install) mein chalti thi. Yani expiry ke baad 30 din ka grace period
# khatam hone par bhi kuch nahi hota tha: wo poora hissa likha gaya,
# documented bhi hua, magar KABHI CHALA HI NAHI.
#
# Yahan is liye hai ke yeh har dafa software chalne par dekhe. Script
# khud cloud par skip kar jati hai, aur grace ke andar kuch nahi mitati
# — sirf batati hai ke kitne din bache hain.
$lic = Invoke-Native -Exe $phpExe -Arguments @('-c', "$phpIni",
  (Join-Path $root 'scripts\licence_enforce.php'))
if ("$lic" -match 'LICENCE_EXPIRED|LICENCE_GRACE_OVER') {
  "$lic".Split("`n") | Where-Object { $_ -match '\S' } | Select-Object -First 3 |
    ForEach-Object { Say "  $_" 'Yellow' }
}

# ---------- 4) find a genuinely free port ----------
function Test-PortFree([int]$p) {
  try {
    $l = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Any, $p)
    $l.Start(); $l.Stop(); return $true
  } catch { return $false }
}
$port = 0
for ($p = 8080; $p -lt 8120; $p++) { if (Test-PortFree $p) { $port = $p; break } }
if ($port -eq 0) { Bad 'No free port available (8080-8119).'; Read-Host 'Press Enter to close'; exit 1 }

# ---------- 5) start the web server ----------
Say "Starting the software on http://localhost:$port ..." 'Cyan'
# NOTE: Start-Process does NOT quote arguments automatically. If the folder
# name contains spaces or brackets - e.g. "smartpos_by_wabwar-a (1)" - an
# unquoted path breaks apart and PHP reports "Could not open input file".
# So we build one properly quoted argument string.
# V68 — TABLET KE LIYE LAN PAR SUNO.
# Pehle server sirf 127.0.0.1 par sunta tha, yani tablet ya koi bhi
# doosra device us tak POHANCH HI NAHI SAKTA tha - chahe wo isi WiFi
# par ho. Tablet ka poora kaam bana hua tha magar darwaza band tha.
#
# Hifazat: server khulne se koi khatra nahi kyunke har request pehle
# login/pairing se guzarti hai (router.php + api.php). Bina paired
# device ke sirf login page milta hai.
$phpArgs = '-c "{0}" -S 0.0.0.0:{1} -t "public" "public/router.php"' -f $phpIni, $port
$srv = Start-Process -FilePath $phpExe `
        -ArgumentList $phpArgs `
        -WorkingDirectory $root -NoNewWindow -PassThru `
        -RedirectStandardOutput $outLog -RedirectStandardError $errLog

# ---------- 6) health check (browser only opens when it really responds) ----------
$up = $false
for ($i = 0; $i -lt 30; $i++) {
  Start-Sleep -Milliseconds 700
  if ($srv.HasExited) { break }
  try {
    $r = Invoke-WebRequest -Uri "http://127.0.0.1:$port/login.html" -UseBasicParsing -TimeoutSec 3
    if ($r.StatusCode -ge 200) { $up = $true; break }
  } catch {
    if ($_.Exception.Response) { $up = $true; break }   # any HTTP reply means it is listening
  }
}

if (-not $up) {
  Bad ''
  Bad 'The software did not start. Details below:'
  foreach ($f in @($errLog, $outLog)) {
    if (Test-Path $f) {
      $lines = Get-Content $f -Tail 12 -ErrorAction SilentlyContinue
      if ($lines) { $lines | ForEach-Object { Bad "  $_" } }
    }
  }
  Bad ''
  Bad "Log files: storage\logs\server.err.log"
  if (-not $srv.HasExited) { Stop-Process -Id $srv.Id -Force -ErrorAction SilentlyContinue }
  & powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1" -StopServer | Out-Null
  Read-Host 'Press Enter to close'; exit 1
}

# ---------- background auto-sync (cloud <-> local) ----------
$syncArgs = '-c "{0}" "scripts/sync_loop.php"' -f $phpIni
# PowerShell stdout aur stderr ke liye EK hi file qubool nahi karta:
# "RedirectStandardOutput and RedirectStandardError are same". Isi wajah
# se auto-sync offline node par kabhi start hi nahi hoti thi aur user ko
# har baar 'Sync now' haath se dabana parta tha. Do alag files.
$syncLog  = Join-Path $logDir 'sync.log'
$syncErr  = Join-Path $logDir 'sync.err.log'
$sync = $null
try {
  $sync = Start-Process -FilePath $phpExe -ArgumentList $syncArgs `
          -WorkingDirectory $root -NoNewWindow -PassThru `
          -RedirectStandardOutput $syncLog -RedirectStandardError $syncErr
  Say 'Auto-sync started (runs in the background).' 'DarkGray'
} catch {
  # Pehle yahan sirf "data will sync when it does" likha aata tha - be-maani.
  # User ko pata hi nahi chalta tha ke sync chal hi nahi rahi aur kyun.
  Say 'AUTO-SYNC DID NOT START:' 'Red'
  Say ("   " + $_.Exception.Message) 'DarkYellow'
  Say "   Log: $syncLog" 'DarkGray'
  Say '   The software keeps running, but data will NOT move to or from the cloud.' 'DarkYellow'
  Say '   You can run it by hand with "Sync now" on the dashboard.' 'DarkGray'
}

# ---------- browser ----------
# Pehle sirf `Start-Process <url>` tha, yani system ka DEFAULT browser.
# Agar us PC ka default browser toota hua ho (misal Firefox ka
# "Couldn't load XPCOM"), to user ko lagta tha software nahi chala --
# halanke server bilkul theek chal raha hota tha.
# Ab: default try -> Edge -> Chrome -> aur har soorat mein address saaf
# screen par, taake user khud khol sake.
$url = "http://localhost:$port/login.html"
$opened = $false
try { Start-Process $url -ErrorAction Stop; $opened = $true } catch { }

if (-not $opened) {
  foreach ($b in @(
      "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
      "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
      "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
      "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe")) {
    if (Test-Path $b) {
      try { Start-Process -FilePath $b -ArgumentList $url -ErrorAction Stop; $opened = $true; break } catch { }
    }
  }
}

if (-not $opened) {
  Write-Host ''
  Say 'The browser could not be opened automatically.' 'Yellow'
  Say "Open Chrome or Edge and go to:  $url" 'Yellow'
}

Write-Host ''
Say "The software is running at http://localhost:$port" 'Green'
# ---------- LAN address (tablets ke liye) ----------
# Router aksar PC ko nayi IP de deta hai, is liye har start par asli IP
# dhoond kar dikhate hain - warna agle din tablet kaam karna band kar
# deta hai aur kisi ko wajah samajh nahi aati.
$lanIp = $null
try {
  $lanIp = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop |
            Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' -and $_.PrefixOrigin -ne 'WellKnown' } |
            Sort-Object -Property SkipAsSource, InterfaceIndex |
            Select-Object -First 1 -ExpandProperty IPAddress)
} catch {
  try {
    $lanIp = ([Net.Dns]::GetHostAddresses([Net.Dns]::GetHostName()) |
              Where-Object { $_.AddressFamily -eq 'InterNetwork' -and $_.IPAddressToString -notmatch '^(127\.|169\.254\.)' } |
              Select-Object -First 1).IPAddressToString
  } catch { }
}
if ($lanIp) {
  Write-Host ''
  Say '  TABLETS / OTHER DEVICES ON THIS WIFI' 'Cyan'
  Say ("     http://{0}:{1}" -f $lanIp, $port) 'White'
  Say '     Open the POS -> "Connect a tablet" to show a QR code.' 'DarkGray'
  try { Set-Content -Path (Join-Path $logDir 'lan_url.txt') -Value ("http://{0}:{1}" -f $lanIp, $port) -ErrorAction SilentlyContinue } catch { }
}

# ---------- V75: auto-update ----------
# Har start par background mein check: portal par naya build hai to
# package khud aa jata hai. INSTALL karna phir bhi user ke DABANE par
# hota hai -- chalte POS ko beech mein badalna kabhi theek nahi.
# V163 — jab update ka paigham chhapa ho to window ko tray mein chhupaya
# NahI jata. Warna wo paigham teen second mein gayab ho jata aur kisi ko
# kabhi pata na chalta ke naya build para hai.
$holdWindow = $false

if (Test-Path (Join-Path $root 'updates\ready.txt')) {
  Write-Host ''
  Say '  A NEW UPDATE IS DOWNLOADED AND READY' 'Cyan'
  Say '  Stop SmartPOS (tray icon -> Stop), then run UPDATE.bat to apply it.' 'White'
  Write-Host ''
  $holdWindow = $true
} elseif (Test-Path (Join-Path $root 'updates\available.txt')) {
  # Naya build hai magar utara nahi gaya - faisla user ka.
  $av = (Get-Content (Join-Path $root 'updates\available.txt') -ErrorAction SilentlyContinue |
         Select-Object -First 1)
  Write-Host ''
  Say ("  A NEW UPDATE IS AVAILABLE: " + $av) 'Cyan'
  Say '  It has NOT been downloaded. Run UPDATE.bat to get and apply it.' 'White'
  Say '  (or ignore this - your software keeps working as it is).' 'DarkGray'
  Write-Host ''
  $holdWindow = $true
} else {
  Start-Job -ScriptBlock {
    param($root, $phpExe, $phpIni)
    Start-Sleep -Seconds 45
    # V90 — SIRF CHECK, download NAHI.
    # Pehle yahan `--download` tha: naya build khud aa jata tha. Malik ne
    # kaha yeh us ki marzi honi chahiye — kisi ka internet mehnga hai,
    # kisi ka slow, aur 20 MB bina poochhe utaar lena theek nahi.
    # Ab: sirf pata karo ke naya build hai. Download tab jab user kahe.
    $a = '-c "{0}" "scripts/self_update.php"' -f $phpIni
    try {
      Start-Process -FilePath $phpExe -ArgumentList $a -WorkingDirectory $root -NoNewWindow -Wait
    } catch { }
  } -ArgumentList $root, $phpExe, $phpIni | Out-Null
}

Say 'This window will move to the tray (bottom-right) in a moment.' 'DarkGray'
Say '   Double-click the tray icon to bring it back, or right-click for Stop.' 'DarkGray'
Say "If the browser shows an error, open a different browser and go to http://localhost:$port" 'DarkGray'
Write-Host ''

# ---------- sync watchdog ----------
# Pehle sync loop chup-chaap mar sakta tha aur kisi ko pata na chalta.
# Ab har 30 second dekha jata hai; mara ho to dobara chala diya jata hai.
$watch = Start-Job -ScriptBlock {
  param($root, $phpExe, $phpIni, $logDir)
  while ($true) {
    Start-Sleep -Seconds 30
    $pidFile = Join-Path $logDir 'sync_loop.pid'
    $alive = $false
    if (Test-Path $pidFile) {
      $sp = (Get-Content $pidFile -ErrorAction SilentlyContinue | Select-Object -First 1)
      if ($sp) { $alive = [bool](Get-Process -Id ([int]$sp) -ErrorAction SilentlyContinue) }
    }
    if (-not $alive) {
      $a = '-c "{0}" "scripts/sync_loop.php"' -f $phpIni
      $lg = Join-Path $logDir 'sync.log'
      $le = Join-Path $logDir 'sync.err.log'   # stdout/stderr ek hi file nahi ho sakti
      try {
        Start-Process -FilePath $phpExe -ArgumentList $a -WorkingDirectory $root -NoNewWindow `
          -RedirectStandardOutput $lg -RedirectStandardError $le | Out-Null
      } catch { }
    }
  }
} -ArgumentList $root, $phpExe, $phpIni, $logDir

# ============================================================
#  V163 — SYSTEM TRAY
#
#  MASLA
#  Software chalne ke baad bhi yeh kaali CMD window screen par pari
#  rehti thi. Counter par customer ke saamne yeh acha nazar nahi aata,
#  aur minimize kar dene par bhi taskbar ghera rehti thi.
#
#  YEH WINDOW BEKAR NAHI HAI — PHP server is ka child hai aur window
#  band hote hi neeche wala cleanup chalta hai (server, sync aur
#  MariaDB teenon theek se band). Is liye ise sirf "gayab" nahi kiya
#  ja sakta: band karne ka koi raasta bacha rehna chahiye.
#
#  HAL
#  Window chhup jati hai aur us ki jagah TRAY ICON aa jata hai:
#     double-click   -> window wapas (error/log dekhne ke liye)
#     right-click    -> Open POS  |  Show window  |  Stop SmartPOS
#  "Stop" wahi purana cleanup chalata hai jo pehle window band karne
#  par chalta tha.
#
#  SERVER KHUD MAR JAYE TO CHHUPNA BAND. Har 5 second dekha jata hai;
#  server gir jaye to window khud saamne aa jati hai aur tray par
#  ittila bhi aati hai — warna user ko lagta rahega sab theek hai.
#
#  AGAR TRAY NA BAN SAKE (purana Windows, .NET ka masla, policy) to
#  purana tareeqa hi chalta hai — window khuli rehti hai. Software
#  kisi soorat rukna nahi chahiye.
# ============================================================
$trayOk = $false
try {
  Add-Type -AssemblyName System.Windows.Forms -ErrorAction Stop
  Add-Type -AssemblyName System.Drawing       -ErrorAction Stop
  Add-Type -Namespace SmartPos -Name NativeWin -ErrorAction Stop -MemberDefinition @'
    [DllImport("kernel32.dll")] public static extern System.IntPtr GetConsoleWindow();
    [DllImport("user32.dll")]   public static extern bool ShowWindow(System.IntPtr hWnd, int nCmdShow);
    [DllImport("user32.dll")]   public static extern bool SetForegroundWindow(System.IntPtr hWnd);
'@
  $trayOk = $true
} catch { $trayOk = $false }

if ($trayOk) {

  $script:hWnd   = [SmartPos.NativeWin]::GetConsoleWindow()
  $script:srvP   = $srv
  $script:syncP  = $sync
  $script:appUrl = $url
  $script:appRoot= $root
  $SW_HIDE = 0; $SW_SHOW = 5; $SW_RESTORE = 9

  function Show-PosWindow {
    if ($script:hWnd -ne [IntPtr]::Zero) {
      [SmartPos.NativeWin]::ShowWindow($script:hWnd, 9)  | Out-Null   # SW_RESTORE
      [SmartPos.NativeWin]::ShowWindow($script:hWnd, 5)  | Out-Null   # SW_SHOW
      [SmartPos.NativeWin]::SetForegroundWindow($script:hWnd) | Out-Null
    }
  }
  function Hide-PosWindow {
    if ($script:hWnd -ne [IntPtr]::Zero) {
      [SmartPos.NativeWin]::ShowWindow($script:hWnd, 0) | Out-Null    # SW_HIDE
    }
  }

  # ---- tray icon ----
  $ico = $null
  try {
    $icoPath = Join-Path $root 'public\assets\app.ico'
    if (Test-Path $icoPath) { $ico = New-Object System.Drawing.Icon($icoPath) }
  } catch { }
  if (-not $ico) { $ico = [System.Drawing.SystemIcons]::Application }

  $script:ni = New-Object System.Windows.Forms.NotifyIcon
  $script:ni.Icon = $ico
  # NotifyIcon.Text ki hadd 63 harf hai — is se zyada par exception aata
  # hai aur poora tray icon ban-na hi band ho jata hai.
  $tip = "$bizName - POS on port $port"
  if ($tip.Length -gt 62) { $tip = $tip.Substring(0, 62) }
  $script:ni.Text = $tip
  $script:ni.Visible = $true

  # ---- band karne ka wahi purana cleanup ----
  $script:stopping = $false
  function Stop-PosAll {
    if ($script:stopping) { return }
    $script:stopping = $true
    try { if ($script:ni) { $script:ni.Visible = $false; $script:ni.Dispose() } } catch { }
    try { Stop-Process -Id $script:srvP.Id -ErrorAction SilentlyContinue } catch { }
    try { if ($script:syncP) { Stop-Process -Id $script:syncP.Id -Force -ErrorAction SilentlyContinue } } catch { }
    try {
      & powershell.exe -NoProfile -ExecutionPolicy Bypass `
        -File (Join-Path $script:appRoot 'tools\resolve_mariadb.ps1') -StopServer | Out-Null
    } catch { }
    try { [System.Windows.Forms.Application]::Exit() } catch { }
  }

  # ---- right-click ka menu ----
  $menu = New-Object System.Windows.Forms.ContextMenuStrip

  $miOpen = $menu.Items.Add('Open POS')
  $miOpen.add_Click({ try { Start-Process $script:appUrl } catch { } })

  $miShow = $menu.Items.Add('Show window')
  $miShow.add_Click({ Show-PosWindow })

  $menu.Items.Add('-') | Out-Null

  $miStop = $menu.Items.Add('Stop SmartPOS')
  $miStop.add_Click({
    $ans = [System.Windows.Forms.MessageBox]::Show(
      'Stop SmartPOS? The POS will close for everyone on this counter.',
      'SmartPOS', 'YesNo', 'Warning')
    if ($ans -eq 'Yes') { Stop-PosAll }
  })

  $script:ni.ContextMenuStrip = $menu
  $script:ni.add_MouseDoubleClick({ Show-PosWindow })

  # ---- server gir jaye to chhupna band ----
  $script:timer = New-Object System.Windows.Forms.Timer
  $script:timer.Interval = 5000
  $script:timer.add_Tick({
    if ($script:srvP.HasExited -and -not $script:stopping) {
      $script:timer.Stop()
      try {
        $script:ni.BalloonTipTitle = 'SmartPOS stopped'
        $script:ni.BalloonTipText  = 'The POS server is not running. The window has been opened so you can read the message.'
        $script:ni.ShowBalloonTip(10000)
      } catch { }
      Show-PosWindow
    }
  })
  $script:timer.Start()

  if ($holdWindow) {
    # Update ka paigham screen par hai — ise chhupana nahi. Tray icon ban
    # chuka hai, is liye user jab chahe window band kar sakta hai.
    Say 'Tray icon is ready. This window is staying open so you can read the update notice.' 'DarkGray'
  } else {
    Start-Sleep -Seconds 3      # user ko "running" wala paigham parhne ka waqt
    Hide-PosWindow
  }

  [System.Windows.Forms.Application]::Run()   # tray ka message loop

  Stop-PosAll

} else {
  Say 'Tray icon could not be created - this window will stay open.' 'DarkYellow'
  Say 'Keep this window open. Closing it will stop the software.' 'DarkGray'
  try { Wait-Process -Id $srv.Id } finally {
    Stop-Process -Id $srv.Id -ErrorAction SilentlyContinue
    if ($sync) { Stop-Process -Id $sync.Id -Force -ErrorAction SilentlyContinue }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1" -StopServer | Out-Null
  }
}

# build: V164 build 2026-10-25

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

function Line { Write-Host ("=" * 60) -ForegroundColor DarkGray }
function Say($m,$c='Gray'){ Write-Host $m -ForegroundColor $c }
function Bad($m){ Write-Host $m -ForegroundColor Red }

# ---------- product / company info ----------
$product='SmartPOS'; $company='Wabwar Software House'; $version='1.0.0'
$phone='+92 300 0000000'; $website='https://wabwar.com'; $email='support@wabwar.com'
$bizName='SmartPOS'; $branch=''
if (Test-Path "$root\runtime\app.info") {
  try {
    $i = Get-Content "$root\runtime\app.info" -Raw | ConvertFrom-Json
    if ($i.name){$bizName=$i.name}; if ($i.branch){$branch=$i.branch}
    if ($i.product){$product=$i.product}; if ($i.company){$company=$i.company}
    if ($i.version){$version=$i.version}; if ($i.phone){$phone=$i.phone}
    if ($i.website){$website=$i.website}; if ($i.email){$email=$i.email}
  } catch {}
}

Write-Host ''
Line
Write-Host "  $bizName" -ForegroundColor Green
Line
Write-Host "  Product        : $product" -ForegroundColor White
if ($branch) { Write-Host "  Branch         : $branch" -ForegroundColor White }
Write-Host "  Company        : $company" -ForegroundColor White
Write-Host "  Version        : $version" -ForegroundColor White
Write-Host "  Contact number : $phone"   -ForegroundColor White
Write-Host "  Website        : $website" -ForegroundColor White
Write-Host "  Email          : $email"   -ForegroundColor White
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
$check = & "$phpExe" -c "$phpIni" -r "require '$rootFwd/runtime/boot.php'; SealedApp::boot('$rootFwd'); echo 'APP_OK';" 2>&1
if ("$check" -notmatch 'APP_OK') {
  Bad 'The application could not start:'
  "$check".Split("`n") | Select-Object -First 6 | ForEach-Object { Bad "  $_" }
  Bad ''
  Bad 'Tip: delete the runtime\php folder and run INSTALL_OFFLINE.bat again.'
  Read-Host 'Press Enter to close'; exit 1
}

# ---------- 4) find a genuinely free port ----------
function Test-PortFree([int]$p) {
  try {
    $l = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Parse('127.0.0.1'), $p)
    $l.Start(); $l.Stop(); return $true
  } catch { return $false }
}
$port = 0
for ($p = 8080; $p -lt 8120; $p++) { if (Test-PortFree $p) { $port = $p; break } }
if ($port -eq 0) { Bad 'No free port available (8080-8119).'; Read-Host 'Press Enter to close'; exit 1 }

# ---------- 5) start the web server ----------
Say "Starting the software on http://localhost:$port ..." 'Cyan'
$srv = Start-Process -FilePath $phpExe `
        -ArgumentList @('-c', $phpIni, '-S', "127.0.0.1:$port", '-t', 'public', 'public/router.php') `
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

Start-Process "http://localhost:$port/login.html"

Write-Host ''
Say "The software is running at http://localhost:$port" 'Green'
Say 'Keep this window open. Closing it will stop the software.' 'DarkGray'
Write-Host ''

try { Wait-Process -Id $srv.Id } finally {
  Stop-Process -Id $srv.Id -ErrorAction SilentlyContinue
  & powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1" -StopServer | Out-Null
}

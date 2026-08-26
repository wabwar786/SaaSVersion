# ============================================================
# install_offline.ps1 - One time setup for the offline package
#   1) Verify the sealed package
#   2) Prepare a private PHP runtime (nothing installed on Windows)
#   3) Prepare the portable database
#   4) Create the database schema and business setup
#   5) Create a Desktop shortcut
# ============================================================
$ErrorActionPreference = 'Stop'
$ProgressPreference    = 'SilentlyContinue'   # hide noisy progress bars
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Line { Write-Host ("=" * 60) -ForegroundColor DarkGray }
function Step($n, $m) { Write-Host "[$n/5] $m" -ForegroundColor Cyan }
function Info($m) { Write-Host "      $m" -ForegroundColor Gray }
function Good($m) { Write-Host "      $m" -ForegroundColor Green }
function Bad($m)  { Write-Host "      $m" -ForegroundColor Red }

# ---------- product / company info ----------
$product = 'SmartPOS'
$company = 'Wabwar Software House'
$version = '1.0.0'
$phone   = '+92 300 0000000'
$website = 'https://wabwar.com'
$email   = 'support@wabwar.com'
$bizName = 'SmartPOS'
$branch  = ''

if (Test-Path "$root\runtime\app.info") {
  try {
    $info = Get-Content "$root\runtime\app.info" -Raw | ConvertFrom-Json
    if ($info.name)    { $bizName = $info.name }
    if ($info.branch)  { $branch  = $info.branch }
    if ($info.product) { $product = $info.product }
    if ($info.company) { $company = $info.company }
    if ($info.version) { $version = $info.version }
    if ($info.phone)   { $phone   = $info.phone }
    if ($info.website) { $website = $info.website }
    if ($info.email)   { $email   = $info.email }
  } catch {}
}

Write-Host ''
Line
Write-Host "       $product - ONE TIME SETUP" -ForegroundColor Green
Line
Write-Host ''
Info 'This setup prepares PHP and the local database, creates the'
Info 'database, and adds a Desktop shortcut. Nothing is installed on'
Info 'Windows - everything stays inside this folder.'
Write-Host ''

# ---------- 1) package check ----------
Step 1 'Verifying package...'
if (-not (Test-Path "$root\runtime\app.sealed")) {
  Bad 'Package is incomplete (runtime\app.sealed not found).'
  Bad 'Please download the offline version again from the portal.'
  exit 1
}
Good 'Package verified.'

# ---------- 2) PHP runtime ----------
Step 2 'Preparing PHP runtime...'
$phpOut = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_php.ps1" 2>&1
if ($LASTEXITCODE -ne 0) {
  Bad 'PHP runtime could not be prepared.'
  $phpOut | Select-Object -Last 5 | ForEach-Object { Bad "$_" }
  exit 1
}

# Use ONLY the private PHP inside this package. Any PHP installed on the PC
# (XAMPP / WAMP / Laragon / Workbench bundles) is deliberately ignored.
$phpExe = $null
foreach ($l in ($phpOut | ForEach-Object { "$_" })) {
  $t = "$l".Trim()
  if ($t.ToLower().EndsWith('php.exe') -and (Test-Path $t)) { $phpExe = $t }
}
if (-not $phpExe) {
  $local = Get-ChildItem -Path (Join-Path $root 'runtime\php') -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue |
           Select-Object -First 1
  if ($local) { $phpExe = $local.FullName }
}
if (-not $phpExe -or -not (Test-Path $phpExe)) {
  Bad 'The private PHP runtime could not be located.'
  Bad 'Delete the runtime\php folder and run this setup again.'
  exit 1
}
$phpIni = Join-Path (Split-Path $phpExe) 'php.ini'

# ---------- 3) portable database ----------
Step 3 'Preparing portable database...'
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1"
if ($LASTEXITCODE -ne 0) { Bad 'Portable database could not be prepared.'; exit 1 }

# ---------- 4) schema + business setup ----------
Step 4 'Creating database schema and business setup...'
$rootFwd = $root -replace '\\', '/'
$scripts = @(
  'install_schema','migrate_platform','migrate_sync','migrate_bridge',
  'migrate_menu_image','migrate_branding','migrate_qr_orders','migrate_devices','migrate_shifts',
  'seed_platform_modules','bootstrap_offline','seed_roles','ensure_default_admin'
)
$failed = 0
foreach ($s in $scripts) {
  $code = "require '$rootFwd/runtime/boot.php'; SealedApp::boot('$rootFwd'); SealedApp::run('scripts/$s.php');"
  $out = & "$phpExe" -c "$phpIni" -r $code 2>&1
  if ($LASTEXITCODE -ne 0) {
    $failed++
    Bad "Step failed: $s"
    $out | Select-Object -First 3 | ForEach-Object { Bad "  $_" }
  }
}
if ($failed -gt 0) { Bad "$failed setup step(s) failed. Setup cannot continue."; exit 1 }
Good 'Database is ready.'

# ---------- 5) desktop shortcut ----------
Step 5 'Creating Desktop shortcut...'
$desktop = [Environment]::GetFolderPath('Desktop')
$lnkPath = Join-Path $desktop ("$bizName.lnk")
$ws = New-Object -ComObject WScript.Shell
$sc = $ws.CreateShortcut($lnkPath)
$sc.TargetPath       = Join-Path $root 'START_OFFLINE.bat'
$sc.WorkingDirectory = $root
$sc.WindowStyle      = 1
$sc.Description      = "$bizName - Offline POS"
$icon = Join-Path $root 'public\assets\app.ico'
if (Test-Path $icon) { $sc.IconLocation = $icon }
$sc.Save()
Good "Shortcut created on Desktop: $bizName"

# ---------- summary ----------
Write-Host ''
Line
Write-Host '  SETUP COMPLETE' -ForegroundColor Green
Line
Write-Host ''
Write-Host "  Business       : $bizName" -ForegroundColor White
if ($branch) { Write-Host "  Branch         : $branch" -ForegroundColor White }
Write-Host "  Product        : $product" -ForegroundColor White
Write-Host "  Company        : $company" -ForegroundColor White
Write-Host "  Version        : $version" -ForegroundColor White
Write-Host "  Contact number : $phone"   -ForegroundColor White
Write-Host "  Website        : $website" -ForegroundColor White
Write-Host "  Email          : $email"   -ForegroundColor White
Write-Host ''
Write-Host '  Start the software from the Desktop shortcut.' -ForegroundColor Cyan
Write-Host ''
exit 0

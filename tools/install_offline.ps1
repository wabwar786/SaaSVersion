# ============================================================
# install_offline.ps1 — one-time setup for the offline package
#   • PHP + MariaDB resolve/check (windows_bootstrap.ps1 reuse)
#   • local DB create + schema + tenant stamp from config/offline.php
#   • Desktop shortcut ("<Business> POS")
# ============================================================
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

Write-Host "[1/4] Configuration parh rahe hain..." -ForegroundColor Cyan
if (-not (Test-Path "$root\config\offline.php")) {
  Write-Host "config\offline.php nahi mili. Portal se dobara download karein." -ForegroundColor Red
  exit 1
}

# business name nikalo (shortcut ke naam ke liye)
$cfgTxt = Get-Content "$root\config\offline.php" -Raw
$bizName = 'Restaurant POS'
if ($cfgTxt -match "'name'\s*=>\s*'([^']+)'") { $bizName = $Matches[1] }

Write-Host "[2/5] PHP runtime tayyar kar rahe hain (system par kuch install nahi hoga)..." -ForegroundColor Cyan
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_php.ps1"
if ($LASTEXITCODE -ne 0) { Write-Host "PHP runtime tayyar nahi ho saka." -ForegroundColor Red; exit 1 }

Write-Host "[3/5] Portable MariaDB tayyar kar rahe hain..." -ForegroundColor Cyan
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1"
if ($LASTEXITCODE -ne 0) { Write-Host "Portable database tayyar nahi ho saka." -ForegroundColor Red; exit 1 }

Write-Host "[4/5] Database schema aur business setup..." -ForegroundColor Cyan
$php = Get-ChildItem -Path "$root\runtime" -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $php) { $php = Get-Command php -ErrorAction SilentlyContinue }
$phpExe = if ($php.FullName) { $php.FullName } else { $php.Source }
foreach ($s in @('install_schema','migrate_platform','migrate_sync','migrate_bridge',
                 'migrate_menu_image','migrate_branding','migrate_qr_orders',
                 'seed_platform_modules','bootstrap_offline','seed_roles','ensure_default_admin')) {
  & $phpExe -r "require '$($root -replace '\\','/')/runtime/boot.php'; SealedApp::boot('$($root -replace '\\','/')'); SealedApp::run('scripts/$s.php');" 2>&1 | Out-Null
}

Write-Host "[5/5] Desktop shortcut bana rahe hain..." -ForegroundColor Cyan
$desktop = [Environment]::GetFolderPath('Desktop')
$lnkPath = Join-Path $desktop ("$bizName.lnk")
$ws = New-Object -ComObject WScript.Shell
$sc = $ws.CreateShortcut($lnkPath)
$sc.TargetPath       = Join-Path $root 'START_RESTAURANT.bat'
$sc.WorkingDirectory = $root
$sc.WindowStyle      = 7
$sc.Description      = "$bizName - Offline POS"
$icon = Join-Path $root 'public\assets\app.ico'
if (Test-Path $icon) { $sc.IconLocation = $icon }
$sc.Save()

Write-Host ""
Write-Host "Setup mukammal! Desktop par '$bizName' shortcut maujood hai." -ForegroundColor Green
exit 0

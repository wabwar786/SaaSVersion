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

Write-Host "[2/4] PHP / database check..." -ForegroundColor Cyan
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\windows_bootstrap.ps1" -SetupOnly
if ($LASTEXITCODE -ne 0) { Write-Host "Database setup mukammal nahi hua." -ForegroundColor Red; exit 1 }

Write-Host "[3/4] Offline config activate kar rahe hain..." -ForegroundColor Cyan
# AIO_CONFIG ko offline.php par point karo (START file isay parhti hai)
Set-Content -Path "$root\config\ACTIVE_CONFIG" -Value "config/offline.php" -Encoding ASCII

Write-Host "[4/4] Desktop shortcut bana rahe hain..." -ForegroundColor Cyan
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

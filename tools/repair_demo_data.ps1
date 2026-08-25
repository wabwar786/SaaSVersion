$ErrorActionPreference="Stop"
$ProjectRoot=Split-Path -Parent $PSScriptRoot
$Selected=Join-Path $ProjectRoot "runtime\selected_php.txt"
$Ini=Join-Path $ProjectRoot "runtime\config\php.ini"

if(-not (Test-Path $Selected)){
  Write-Host "Please run START_RESTAURANT.bat first."
  Read-Host "Press Enter"
  exit 1
}

$PhpExe=(Get-Content $Selected -Raw).Trim()
& $PhpExe -c $Ini (Join-Path $ProjectRoot "scripts\seed_full_demo.php")

Write-Host ""
Write-Host "Demo database repair finished."
Write-Host "Any optional problem was logged and will not block the software."
Write-Host "Log: storage\logs\demo-seed.log"
Write-Host ""
Read-Host "Press Enter to close"

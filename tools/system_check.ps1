$ProjectRoot=Split-Path -Parent $PSScriptRoot
Write-Host ""
Write-Host "Restaurant Software - System Check"
Write-Host "=================================="
Write-Host ""

$sel=Join-Path $ProjectRoot "runtime\selected_php.txt"
if(Test-Path $sel){
  $php=(Get-Content $sel -Raw).Trim()
  Write-Host "Selected PHP: $php"
  if(Test-Path $php){
    & $php -c (Join-Path $ProjectRoot "runtime\config\php.ini") -v
    Write-Host ""
    & $php -c (Join-Path $ProjectRoot "runtime\config\php.ini") -m
  }else{Write-Host "Selected php.exe is missing."}
}else{
  Write-Host "PHP has not been selected yet."
  Write-Host "Run START_RESTAURANT.bat."
}
Write-Host ""
Read-Host "Press Enter to close"

# build: V17.1 build 2026-08-25

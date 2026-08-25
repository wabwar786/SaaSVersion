@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ps=Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" ^| Where-Object { $_.CommandLine -match '127.0.0.1:8080' -and $_.CommandLine -match 'aio_restaurant_php|RestaurantSoftware' }; foreach($p in $ps){ Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue }; Write-Host 'Old Restaurant PHP server check completed.'"
pause

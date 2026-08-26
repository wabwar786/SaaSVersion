@echo off
setlocal
cd /d "%~dp0"
title SmartPOS
color 0A
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\start_offline.ps1"
if errorlevel 1 (
  echo.
  echo The software could not start. Please read the message above.
  echo.
  pause
)

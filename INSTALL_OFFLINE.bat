@echo off
setlocal
cd /d "%~dp0"
title SmartPOS - One Time Setup
color 0A
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\install_offline.ps1"
if errorlevel 1 (
  echo.
  echo Setup did not complete. Please read the message above.
  echo.
  pause
  exit /b 1
)
pause

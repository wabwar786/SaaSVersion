@echo off
setlocal
cd /d "%~dp0"
title Restaurant Software - Offline
color 0A
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\start_offline.ps1"
if errorlevel 1 (
  echo.
  echo Software start nahi ho saka. Upar diya gaya message parhein.
  echo.
  pause
)

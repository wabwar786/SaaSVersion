@echo off
setlocal
cd /d "%~dp0"
title Offline Version - One Time Setup
color 0A
echo.
echo ============================================================
echo        OFFLINE VERSION - ONE TIME SETUP
echo ============================================================
echo.
echo Yeh setup PHP aur local database check karega, database
echo banayega, aur Desktop par shortcut bana dega.
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\install_offline.ps1"
if errorlevel 1 (
  echo.
  echo Setup mukammal nahi hua. Upar diya gaya message parhein.
  echo.
  pause
  exit /b 1
)
echo.
echo Setup mukammal. Desktop par shortcut bana diya gaya hai.
echo.
pause

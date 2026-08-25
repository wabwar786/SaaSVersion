@echo off
setlocal
cd /d "%~dp0"
title Restaurant Software
color 0A
echo.
echo ============================================================
echo             RESTAURANT SOFTWARE
echo ============================================================
echo.
echo Checking PHP, MySQL and local database automatically...
echo No CMD knowledge is required.
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\windows_bootstrap.ps1"
if errorlevel 1 (
  echo.
  echo Setup/start could not complete.
  echo Please read the error message shown on screen.
  echo.
  pause
  exit /b 1
)
exit /b 0

rem build: V17.1 build 2026-08-25

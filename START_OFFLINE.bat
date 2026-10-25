@echo off
setlocal
cd /d "%~dp0"
title SmartPOS
color 0A
REM -STA: tray icon (WinForms NotifyIcon) ko single-threaded apartment
REM chahiye. PowerShell 5 waise bhi STA hota hai, magar saaf likh dena
REM behtar hai -- warna kisi purane Windows par tray chup-chaap na bane.
powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File "%~dp0tools\start_offline.ps1"
if errorlevel 1 (
  echo.
  echo The software could not start. Please read the message above.
  echo.
  pause
)

rem build: V164 build 2026-10-25

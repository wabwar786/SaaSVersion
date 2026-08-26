@echo off
setlocal
cd /d "%~dp0"
title SmartPOS - Diagnostics
color 0E
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\diagnose.ps1"

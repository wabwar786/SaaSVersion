@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\reset_admin_login.ps1"
exit /b %errorlevel%

rem build: V17.1 build 2026-08-25

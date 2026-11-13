@echo off
REM ============================================================
REM  GET UPDATE (FORCE) - V192
REM  Downloads the newest build even when it was published for the
REM  other business type (retail / restaurant). Use only when Wabwar
REM  support asks you to. Install it afterwards with INSTALL_UPDATE.bat
REM  exactly as usual.
REM ============================================================
setlocal
cd /d "%~dp0"
set AIO_UPDATE_FORCE=1
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\update.ps1" -Mode download
echo.
pause

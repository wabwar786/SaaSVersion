@echo off
REM ============================================================
REM  RESET_PASSWORD.bat -- password bhool gaye to yahan se.
REM
REM  Yeh USI computer par chalta hai aur internet ki zaroorat NAHI.
REM  Software band ho to bhi chalta hai (database chalu hona chahiye).
REM ============================================================
setlocal
cd /d "%~dp0"

set PHPEXE=runtime\php\php.exe
set PHPINI=runtime\php\php.ini
if not exist "%PHPEXE%" (
  echo.
  echo   PHP nahi mila. Pehle INSTALL_OFFLINE.bat chalayein.
  echo.
  pause
  exit /b 1
)

echo.
echo   ============================================
echo    SmartPOS - SIGN-IN RECOVERY
echo   ============================================
echo.
echo   Database chalu kar rahe hain...
start /B "" "runtime\mariadb\bin\mysqld.exe" --defaults-file=runtime\mariadb\my.ini >nul 2>&1
timeout /t 6 /nobreak >nul

"%PHPEXE%" -c "%PHPINI%" scripts\reset_local_admin.php

echo.
set /p U=  Sign-in name (khali chhorein to bahar):
if "%U%"=="" goto :done
set /p P=  New password:
if "%P%"=="" goto :done

"%PHPEXE%" -c "%PHPINI%" scripts\reset_local_admin.php --user=%U% --password=%P%

:done
echo.
pause

@echo off
REM ============================================================
REM  RESET_PASSWORD.bat -- password bhool gaye to yahan se.
REM
REM  Yeh USI computer par chalta hai. Internet ki zaroorat NAHI.
REM
REM  V91 -- pehle yahan mysqld.exe ka rasta SEEDHA likha hua tha
REM  (runtime\mariadb\bin\mysqld.exe). Wo ghalat tha: MariaDB apne
REM  version wale folder mein khulta hai, jaise
REM  runtime\mariadb\mariadb-11.4.2-winx64\bin\. Is liye Windows
REM  "cannot find" kehta tha.
REM  Ab wahi script use hoti hai jo software khud use karta hai
REM  (resolve_mariadb.ps1) -- wo exe ko DHOOND kar chalati hai.
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

REM Agar software pehle se chal raha hai to database bhi chalu hai.
echo   Checking the local database...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "tools\resolve_mariadb.ps1"
if errorlevel 1 (
  echo.
  echo   Local database chalu nahi ho saki.
  echo   START_RESTAURANT.bat chala kar software kholein, phir yeh
  echo   file dobara chalayein.
  echo.
  pause
  exit /b 1
)

echo.
"%PHPEXE%" -c "%PHPINI%" scripts\reset_local_admin.php
if errorlevel 1 goto :done

echo.
set /p U=  Sign-in name (khali chhorein to bahar):
if "%U%"=="" goto :done
set /p P=  New password:
if "%P%"=="" goto :done

"%PHPEXE%" -c "%PHPINI%" scripts\reset_local_admin.php --user=%U% --password=%P%

:done
echo.
pause

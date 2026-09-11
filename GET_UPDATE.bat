@echo off
REM ============================================================
REM  GET_UPDATE.bat -- naya build download karein.
REM
REM  Software khud CHECK karta hai ke naya build hai ya nahi, magar
REM  download AAP KI MARZI SE hota hai. Yeh file wohi download karti
REM  hai. Lagane ke liye phir INSTALL_UPDATE.bat.
REM ============================================================
setlocal
cd /d "%~dp0"

set PHPEXE=runtime\php\php.exe
set PHPINI=runtime\php\php.ini
if not exist "%PHPEXE%" (
  echo.
  echo   PHP not found. Pehle INSTALL_OFFLINE.bat run.
  echo.
  pause
  exit /b 1
)

echo.
echo   ============================================
echo    SmartPOS - GET UPDATE
echo   ============================================
echo.
echo   Checking...
"%PHPEXE%" -c "%PHPINI%" scripts\self_update.php

echo.
if exist "updates\offline.txt" (
  echo   Portal tak pohanch nahi hui - internet check please.
  echo   Yeh ka matlab yeh NAHI ke aap ke paas latest build hai.
  echo.
  pause
  exit /b 1
)
if not exist "updates\available.txt" (
  echo   Aap ke paas first se latest build hai. Kuch karna nahi.
  echo.
  pause
  exit /b 0
)

set /p AV=<updates\available.txt
echo   New build: %AV%
echo.
echo   Yeh internet se download hoga (taqreeban 2-5 MB).
set /p GO=  Download please? (Y/N):
if /I not "%GO%"=="Y" (
  echo.
  echo   OK. Aap ka software waise hi chalta rahega.
  echo   Baad mein jab chahein, yeh file again run.
  echo.
  pause
  exit /b 0
)

echo.
echo   Downloading...
"%PHPEXE%" -c "%PHPINI%" scripts\self_update.php --download

echo.
if exist "updates\ready.txt" (
  echo   Download mukammal.
  echo   Ab software band kar ke INSTALL_UPDATE.bat run.
) else (
  echo   Download mukammal failed. Ooper ka paighaam parhein.
)
echo.
pause

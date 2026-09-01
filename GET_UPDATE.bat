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
  echo   PHP nahi mila. Pehle INSTALL_OFFLINE.bat chalayein.
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
if not exist "updates\available.txt" (
  echo   Aap ke paas pehle se latest build hai. Kuch karna nahi.
  echo.
  pause
  exit /b 0
)

set /p AV=<updates\available.txt
echo   New build: %AV%
echo.
echo   Yeh internet se download hoga (taqreeban 2-5 MB).
set /p GO=  Download karein? (Y/N):
if /I not "%GO%"=="Y" (
  echo.
  echo   Theek hai. Aap ka software waise hi chalta rahega.
  echo   Baad mein jab chahein, yeh file dobara chalayein.
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
  echo   Ab software band kar ke INSTALL_UPDATE.bat chalayein.
) else (
  echo   Download mukammal nahi hua. Ooper ka paighaam parhein.
)
echo.
pause

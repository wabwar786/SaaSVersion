@echo off
setlocal
cd /d "%~dp0"
title Restaurant Server - DEBUG
echo ============================================================
echo    RESTAURANT SERVER  -  DEBUG (visible window)
echo    Yeh window server ka ASLI error live dikhata hai.
echo ============================================================
echo.
if not exist "runtime\selected_php.txt" (
  echo Resolving PHP...
  powershell -NoProfile -ExecutionPolicy Bypass -File "tools\resolve_php.ps1"
)
if not exist "runtime\selected_php.txt" (
  echo.
  echo PHP resolve nahi hua. Pehle ek dafa START_RESTAURANT.bat chala lein.
  echo.
  pause
  exit /b 1
)
set /p PHPEXE=<runtime\selected_php.txt
echo Using PHP : %PHPEXE%
echo Ini file  : runtime\config\php.ini
echo URL       : http://127.0.0.1:8940/login.html
echo.
echo ------------------------------------------------------------
echo  Neeche server chal raha hai. Browser mein upar wala URL kholein.
echo  Is window ko band MAT karein jab tak app use ho rahi hai.
echo  Agar server foran ruk jaye to neeche jo likha hai wahi asli wajah hai.
echo ------------------------------------------------------------
echo.
"%PHPEXE%" -c "runtime\config\php.ini" -d display_errors=1 -S 127.0.0.1:8940 -t "public" "public\router.php"
echo.
echo ------------------------------------------------------------
echo  Server band ho gaya. Upar koi error message ho to mujhe bhej dein.
echo ------------------------------------------------------------
pause

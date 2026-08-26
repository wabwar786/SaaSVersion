@echo off
REM ============================================================
REM  RESET_NODE.bat  --  Branch computer ka data saaf karein.
REM
REM  Kab chahiye:
REM    Cloud par reset/purge ho chuka hai magar is computer par
REM    purana data abhi bhi nazar aa raha hai.
REM
REM  V62 ke baad cloud ka reset khud is computer tak pohanch jata
REM  hai (sync tombstones), is liye aam tor par yeh chalane ki
REM  zaroorat NahI hoti. Yeh sirf un cases ke liye hai jahan
REM  reset purane build ke waqt hua tha, ya installation poori
REM  tarah offline hai.
REM
REM  PEHLE BACKUP LEIN. Yeh amal wapas nahi hota.
REM ============================================================
setlocal
cd /d "%~dp0"

set PHPEXE=runtime\php\php.exe
if not exist "%PHPEXE%" set PHPEXE=php

echo.
echo   ============================================
echo    BRANCH COMPUTER RESET
echo   ============================================
echo.
echo    1 = Sirf transactions (menu/users/settings mehfooz)
echo    2 = Sab kuch (sirf admin login bachega)
echo    3 = Pehle sirf ginti dekhein (kuch delete nahi hoga)
echo    0 = Bahar
echo.
set /p CHOICE=   Apna option likhein:

if "%CHOICE%"=="0" goto :end
if "%CHOICE%"=="3" (
    "%PHPEXE%" scripts\node_reset.php --what=txn --dry-run
    goto :done
)

set MODE=txn
if "%CHOICE%"=="2" set MODE=all

echo.
echo   Business ka POORA naam bilkul waisa hi likhein jaisa portal par hai.
set /p BNAME=   Business name:

echo.
"%PHPEXE%" scripts\node_reset.php --what=%MODE% --confirm="%BNAME%"

:done
echo.
echo   Ab START_RESTAURANT.bat se app kholein aur dashboard par
echo   "Sync now" dabayein.
echo.

:end
pause
endlocal

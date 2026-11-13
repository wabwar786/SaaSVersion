@echo off
setlocal
title V193 - delete old files before extracting
REM ============================================================
REM  V193 build 2026-11-13 - deletes ONLY the files this update
REM  replaces, so Windows extraction never asks "Replace or Skip".
REM
REM  HOW TO USE (your local PC, project folder):
REM   1. Copy this file into the project folder (the folder that
REM      has VERSION, public, src and approved_ui).
REM   2. Double-click it.
REM   3. Then extract SaaSVersion_V193_RETAIL.zip into the same folder.
REM ============================================================

cd /d "%~dp0"

if not exist "VERSION" goto WRONGDIR
if not exist "public\api.php" goto WRONGDIR
if not exist "src\Services" goto WRONGDIR

echo Project folder: %CD%
echo Current version:
type VERSION
echo.
echo These files will be deleted (V193 replaces them; files new in
echo V193 show as "not found" - that is fine):
echo.
for %%F in (
  "VERSION"
  "RELEASES.json"
  "public\api.php"
  "public\router.php"
  "src\Auth.php"
  "src\Services\RetailPos.php"
  "src\Services\RetailShift.php"
  "scripts\check_local_schema.php"
  "scripts\migration_order.php"
  "scripts\migrate_retail_v193.php"
  "tools\start_offline.ps1"
  "approved_ui\retail\pos.html"
  "approved_ui\retail\retail_api.js"
) do echo    %%~F

echo.
choice /C YN /M "Delete these files now"
if errorlevel 2 goto CANCEL

set COUNT=0
for %%F in (
  "VERSION"
  "RELEASES.json"
  "public\api.php"
  "public\router.php"
  "src\Auth.php"
  "src\Services\RetailPos.php"
  "src\Services\RetailShift.php"
  "scripts\check_local_schema.php"
  "scripts\migration_order.php"
  "scripts\migrate_retail_v193.php"
  "tools\start_offline.ps1"
  "approved_ui\retail\pos.html"
  "approved_ui\retail\retail_api.js"
) do (
  if exist "%%~F" (
    del /f /q "%%~F"
    if exist "%%~F" (
      echo    FAILED  %%~F   - close the program using it and run again
    ) else (
      echo    deleted %%~F
      set /a COUNT+=1
    )
  ) else (
    echo    not found %%~F  - ok
  )
)

echo.
echo Done. %COUNT% file(s) deleted.
echo NEXT: extract SaaSVersion_V193_RETAIL.zip into this same folder:
echo       %CD%
echo.
pause
exit /b 0

:WRONGDIR
echo This is not the project folder (VERSION / public\api.php / src\Services not found).
echo Copy this file into the project folder and run it there. Nothing was deleted.
pause
exit /b 1

:CANCEL
echo Cancelled. Nothing was deleted.
pause
exit /b 0

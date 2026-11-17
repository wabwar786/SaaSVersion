@echo off
setlocal
title V196 - delete old files before extracting
REM ============================================================
REM  V196 build 2026-11-17 - deletes ONLY the files this update
REM  replaces, so Windows extraction never asks "Replace or Skip".
REM
REM  HOW TO USE (your local PC, project folder):
REM   1. Copy this file into the project folder (the folder that
REM      has VERSION, public, src and approved_ui).
REM   2. Double-click it.
REM   3. Then extract SaaSVersion_V196.zip into the same folder.
REM ============================================================

cd /d "%~dp0"

if not exist "VERSION" goto WRONGDIR
if not exist "public\api.php" goto WRONGDIR
if not exist "src\Services" goto WRONGDIR

echo Project folder: %CD%
echo Current version:
type VERSION
echo.
echo These files will be deleted (V196 replaces them; files new in
echo V196 show as "not found" - that is fine):
echo.
for %%F in (
  "VERSION"
  "RELEASES.json"
  "approved_ui\restaurant_pos.html"
  "public\api.php"
  "src\Services\PosService.php"
  "src\Services\OpsService.php"
  "src\Services\FiscalService.php"
  "src\Services\ReportService.php"
  "src\Services\OwnerMobile.php"
) do echo    %%~F

echo.
choice /C YN /M "Delete these files now"
if errorlevel 2 goto CANCEL

set COUNT=0
for %%F in (
  "VERSION"
  "RELEASES.json"
  "approved_ui\restaurant_pos.html"
  "public\api.php"
  "src\Services\PosService.php"
  "src\Services\OpsService.php"
  "src\Services\FiscalService.php"
  "src\Services\ReportService.php"
  "src\Services\OwnerMobile.php"
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
echo NEXT: extract SaaSVersion_V196.zip into this same folder:
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

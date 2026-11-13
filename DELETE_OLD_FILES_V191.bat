@echo off
setlocal
title V191 - delete old files before extracting
REM ============================================================
REM  V191 build 2026-11-13 - deletes ONLY the files this update
REM  replaces, so Windows extraction never asks "Replace or Skip".
REM
REM  HOW TO USE (shop PC or your local PC):
REM   1. Copy this file into the project folder (the folder that
REM      has VERSION, public, src and approved_ui).
REM   2. Double-click it.
REM   3. Then extract SaaSVersion_V191_RETAIL.zip into the same folder.
REM ============================================================

cd /d "%~dp0"

if not exist "VERSION" goto WRONGDIR
if not exist "public\api.php" goto WRONGDIR
if not exist "src\Services" goto WRONGDIR

echo Project folder: %CD%
echo Current version:
type VERSION
echo.
echo These files will be deleted (V191 replaces them):
echo.

set COUNT=0
for %%F in (
  "VERSION"
  "public\api.php"
  "public\router.php"
  "src\Services\ModuleBridge.php"
  "src\Services\RetailCatalog.php"
  "src\Services\RetailDocs.php"
  "src\Services\RetailPos.php"
  "src\Services\RetailReportService.php"
  "src\Services\RetailShift.php"
  "src\Services\Sync.php"
  "approved_ui\retail\closing.html"
  "approved_ui\retail\doc.js"
  "approved_ui\retail\index.html"
  "approved_ui\retail\module_config.js"
  "approved_ui\retail\pos.html"
  "approved_ui\retail\retail_api.js"
  "approved_ui\retail\shift.html"
) do echo    %%~F

echo.
choice /C YN /M "Delete these files now"
if errorlevel 2 goto CANCEL

for %%F in (
  "VERSION"
  "public\api.php"
  "public\router.php"
  "src\Services\ModuleBridge.php"
  "src\Services\RetailCatalog.php"
  "src\Services\RetailDocs.php"
  "src\Services\RetailPos.php"
  "src\Services\RetailReportService.php"
  "src\Services\RetailShift.php"
  "src\Services\Sync.php"
  "approved_ui\retail\closing.html"
  "approved_ui\retail\doc.js"
  "approved_ui\retail\index.html"
  "approved_ui\retail\module_config.js"
  "approved_ui\retail\pos.html"
  "approved_ui\retail\retail_api.js"
  "approved_ui\retail\shift.html"
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
echo NEXT: extract SaaSVersion_V191_RETAIL.zip into this same folder:
echo       %CD%
echo.
pause
exit /b 0

:WRONGDIR
echo.
echo  This is NOT the project folder:
echo     %CD%
echo  Copy this .bat into the folder that has VERSION, public, src
echo  and approved_ui, then run it again. Nothing was deleted.
echo.
pause
exit /b 1

:CANCEL
echo.
echo Cancelled. Nothing was deleted.
pause
exit /b 1

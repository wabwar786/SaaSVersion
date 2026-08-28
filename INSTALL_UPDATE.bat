@echo off
REM ============================================================
REM  INSTALL_UPDATE.bat -- naya build lagayein.
REM
REM  Software khud roz check karta hai ke portal par naya build hai
REM  ya nahi, aur mil jaye to `updates\` folder mein rakh deta hai.
REM  Yeh file usay lagati hai.
REM
REM  AAP KA DATA MEHFOOZ REHTA HAI: sirf program ki files badalti
REM  hain. Database (data\), settings (config\) aur runtime\ ko
REM  haath nahi lagta.
REM ============================================================
setlocal
cd /d "%~dp0"

if not exist "updates\ready.txt" (
  echo.
  echo   Koi naya update tayyar nahi hai.
  echo   Software roz khud check karta hai; agli dafa internet aane par
  echo   naya build khud aa jayega.
  echo.
  pause
  exit /b 0
)

set /p PKG=<updates\ready.txt
if not exist "updates\%PKG%" (
  echo   Update file nahi mili: updates\%PKG%
  pause
  exit /b 1
)

echo.
echo   ============================================
echo    SmartPOS - UPDATE
echo   ============================================
echo.
echo    Package : %PKG%
echo.
echo    Software band hona chahiye. Agar chal raha hai to pehle
echo    us ki window band karein.
echo.
set /p GO=   Update lagayein? (Y/N):
if /I not "%GO%"=="Y" exit /b 0

set PHPEXE=runtime\php\php.exe
if not exist "%PHPEXE%" set PHPEXE=php

echo.
echo   [1/3] Purani files ka backup...
if not exist "backup" mkdir backup
set STAMP=%DATE:~-4%%DATE:~4,2%%DATE:~7,2%_%TIME:~0,2%%TIME:~3,2%
set STAMP=%STAMP: =0%
"%PHPEXE%" -r "$d='backup/before-%STAMP%';@mkdir($d,0775,true);foreach(['public','src','approved_ui','scripts','tools'] as $f){if(is_dir($f)){$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($f,FilesystemIterator::SKIP_DOTS));foreach($i as $x){if(!$x->isFile())continue;$t=$d.'/'.$x->getPathname();@mkdir(dirname($t),0775,true);@copy($x->getPathname(),$t);}}}echo '      backup: '.$d.PHP_EOL;"

echo   [2/3] Nayi files nikal rahe hain...
"%PHPEXE%" -r "$z=new ZipArchive();if($z->open('updates/%PKG%')!==true){echo '      ERROR: package khul nahi saka'.PHP_EOL;exit(1);} $keep=['data/','config/','runtime/','storage/','backup/','updates/']; $n=0; for($i=0;$i<$z->numFiles;$i++){$e=$z->getNameIndex($i); $skip=false; foreach($keep as $k){if(strpos($e,$k)===0)$skip=true;} if($skip||substr($e,-1)==='/')continue; $z->extractTo('.', $e); $n++;} $z->close(); echo '      '.$n.' files updated'.PHP_EOL;"
if errorlevel 1 goto :fail

echo   [3/3] Database migrations...
"%PHPEXE%" scripts\install_schema.php >nul 2>&1
for %%m in (migrate_sync_columns migrate_delete_support migrate_print_rule_default migrate_fiscal migrate_module_ids) do (
  "%PHPEXE%" scripts\%%m.php >nul 2>&1
)
echo       done

del /q updates\ready.txt >nul 2>&1
del /q updates\available.txt >nul 2>&1

echo.
echo   Update mukammal. Ab START_RESTAURANT.bat se software kholein.
echo   Purani files backup\ folder mein mehfooz hain.
echo.
pause
exit /b 0

:fail
echo.
echo   Update mukammal nahi hua. Purani files backup\ mein hain.
echo   support@wabwar.pk par rabta karein.
echo.
pause
exit /b 1

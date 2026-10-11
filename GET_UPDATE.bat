@echo off
REM ============================================================
REM  GET UPDATE - download the new build (install separately).
REM
REM  Yeh file jaan bujh kar CHHOTI hai. Saara kaam tools\update.ps1
REM  karti hai. Wajah: update ka zip is .bat ko bhi replace karta hai,
REM  aur cmd.exe batch file ko chalte hue line-by-line parhta hai --
REM  khud ko badalna bigaar paida kar sakta hai. PowerShell script
REM  poori pehle memory mein parh li jati hai, is liye mehfooz hai.
REM
REM  Aap ka data mehfooz rehta hai: sirf program ki files badalti hain.
REM ============================================================
setlocal
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\update.ps1" -Mode download
echo.
pause

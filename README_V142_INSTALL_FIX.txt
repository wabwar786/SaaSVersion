============================================================
 V142 — OFFLINE SETUP FIX (VCRUNTIME140 / "Setup did not complete")
 Wabwar Software House
============================================================

WHAT THIS RELEASE FIXES
-----------------------
The offline setup stopped at:

    [2/5] Preparing PHP runtime...
    powershell.exe : php.exe : PHP Warning: 'C:\Windows\SYSTEM32\
    VCRUNTIME140.dll' 14.0 is not compatible with this PHP build
    At D:\MOB\tools\install_offline.ps1:96 char:11
        + FullyQualifiedErrorId : NativeCommandError
    Setup did not complete.

Two problems, both fixed:

 1. That PC has an old Visual C++ runtime (14.0). The package's PHP 8.2
    needs 14.29 or newer.

 2. THE REAL BUG — the repair for problem 1 existed but could never run.
    install_offline.ps1 had $ErrorActionPreference = 'Stop' at the top,
    and line 96 captured PHP's output with 2>&1. In Windows PowerShell
    5.1 that combination turns one stderr line into a TERMINATING error,
    so the script died on line 96 — before reaching the VCRUNTIME repair
    and the ASLR fallback written just below it.

    The same trap was in resolve_php.ps1, resolve_mariadb.ps1 and
    start_offline.ps1. All four are fixed.

Only the Windows installer/launcher scripts changed in V142.
No PHP, no UI, no database changes. Everything from V141 is included.


============================================================
 STEP 1 — DELETE THESE OLD FILES FIRST
============================================================
Windows' "Replace or Skip" dialog silently skips files during
extraction, which leaves old scripts in place. Delete these first, then
extract the new package over the folder.

In your offline package folder (for example D:\MOB):

    tools\install_offline.ps1
    tools\resolve_php.ps1
    tools\resolve_mariadb.ps1
    tools\start_offline.ps1
    tools\fix_vcruntime.ps1
    VERSION
    WHATS_UPDATED.md

One file is NEW and will not exist yet — nothing to delete:

    tools\native_helper.ps1

Do NOT delete anything else. In particular leave these alone:
    runtime\     (your PHP + database live here)
    storage\     (your data, logs, backups)
    config\      (your local settings)


============================================================
 STEP 2 — EXTRACT
============================================================
Extract SaaSVersion_V142.zip over the same folder (D:\MOB).
If Windows still asks "Replace or Skip", choose REPLACE for all.


============================================================
 STEP 3 — VERIFY (search string per file)
============================================================
Open each file in Notepad and use Ctrl+F for the string shown. If the
string is not found, that file did not get replaced — delete it and
extract again.

  FILE                          SEARCH FOR (Ctrl+F)
  ----------------------------  -----------------------------------
  VERSION                       V142
  tools\native_helper.ps1       function Invoke-Native
  tools\install_offline.ps1     Invoke-NativeScript -ScriptPath
  tools\resolve_php.ps1         Invoke-Native -Exe $php
  tools\resolve_mariadb.ps1     Invoke-Native -Exe $mysqlExe
  tools\start_offline.ps1       Invoke-Native -Exe $phpExe
  tools\fix_vcruntime.ps1       Installing the Microsoft Visual C++

Quick check for all of them at once —
RUN THIS ON THE OFFLINE PC, in Command Prompt (CMD), in the package
folder:

    findstr /M /C:"Invoke-Native" tools\*.ps1

You should see these six file names listed:
    tools\fix_vcruntime.ps1
    tools\install_offline.ps1
    tools\native_helper.ps1
    tools\resolve_mariadb.ps1
    tools\resolve_php.ps1
    tools\start_offline.ps1


============================================================
 STEP 4 — RUN SETUP AGAIN
============================================================
ON THE OFFLINE PC: right-click INSTALL_OFFLINE.bat and choose
"Run as administrator".

Administrator matters here: if the Visual C++ runtime has to be
installed, a normal double-click gives exit code 1602/1603 and the
setup will tell you to re-run as administrator anyway.

What you will see now instead of the old error:

  * If that PC has internet —
        This computer has an old Visual C++ runtime; PHP needs a newer one.
        Trying to fix it automatically...
        Internet found. Installing the Microsoft Visual C++ runtime...
        Visual C++ runtime : 100%  complete.
        Installing (about a minute, no questions asked)...
        Fixed - PHP now runs.
    ... and setup carries on to [3/5].

  * If it says "Installed, but this computer needs to be RESTARTED once"
    — restart the PC, then run INSTALL_OFFLINE.bat again.

  * If that PC has NO internet — setup will print the download link and
    stop. See STEP 5.


============================================================
 STEP 5 — NO INTERNET ON THAT PC?
============================================================
Pick either one.

OPTION A (one-time, on that PC)
  On any PC with internet, download:
      https://aka.ms/vs/17/release/vc_redist.x64.exe
  Copy it to the offline PC on a USB stick, run it, then run
  INSTALL_OFFLINE.bat again.

OPTION B (better — fixes this for EVERY future package, and installs
          nothing on the customer's Windows)
  From any Windows PC that already has the Visual C++ 2015-2022 x64
  Redistributable, copy these THREE files out of C:\Windows\System32:

      vcruntime140.dll
      vcruntime140_1.dll
      msvcp140.dll

  Check first: right-click each file -> Properties -> Details ->
  File version must be 14.29 or higher.

  Put them in the package folder:

      D:\MOB\vendor\vcruntime\

  Then run INSTALL_OFFLINE.bat again. Setup copies them next to php.exe.
  Windows looks in a program's own folder before System32, so the old
  copy stops mattering — and nothing is installed on Windows.

  If you also drop those three files into vendor\vcruntime\ in your
  master copy, every offline package you build from now on will carry
  them and this error can never happen again on any customer PC.


============================================================
 IF SETUP STILL FAILS
============================================================
ON THE OFFLINE PC, in Command Prompt (CMD), in the package folder:

    powershell -NoProfile -ExecutionPolicy Bypass -File tools\diagnose.ps1

Send us that output. You will now get a readable reason instead of a
PowerShell exception dump — that is the point of this release.

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
------------------------------------------------------------

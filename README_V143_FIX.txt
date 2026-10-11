============================================================
 V143 — "native_helper.ps1 is not recognized" FIX
 Wabwar Software House
============================================================

WHAT HAPPENED
-------------
    . : The term 'D:\MOB\tools\native_helper.ps1' is not recognized...
    At D:\MOB\tools\start_offline.ps1:21 char:3
        + FullyQualifiedErrorId : CommandNotFoundException
    The software could not start.

V142 added a new file, tools\native_helper.ps1, and five scripts load it
on their first lines. But the offline package is built from a hand-written
list of file names in public/api.php, and that new file was never added to
the list. So your package got the NEW start_offline.ps1 (which needs the
helper) but not the helper itself.

My mistake. Fixed three ways in V143:
  1. The packager now ships the whole tools\ folder, not a list.
  2. All five scripts now work even if that helper file is missing.
  3. A new check catches this before a package is built.


============================================================
 FASTEST FIX — 30 SECONDS, NO RE-EXTRACT
============================================================
If you just want the POS open right now:

Copy the single file

    native_helper.ps1

into

    D:\MOB\tools\

That is all. Double-click START_OFFLINE.bat and the software opens.
(This file is sent alongside this package.)

Then apply the full package below when convenient.


============================================================
 STEP 1 — DELETE THESE OLD FILES FIRST
============================================================
Windows' "Replace or Skip" dialog silently skips files, which is how a
half-applied update happens. Delete these first, then extract.

In your package folder (for example D:\MOB):

    tools\install_offline.ps1
    tools\resolve_php.ps1
    tools\resolve_mariadb.ps1
    tools\start_offline.ps1
    tools\fix_vcruntime.ps1
    tools\native_helper.ps1        (delete it if it is there; usually it is NOT)
    VERSION
    WHATS_UPDATED.md

Do NOT delete anything else. Leave these alone:
    runtime\     (your PHP + database)
    storage\     (your data, logs, backups)
    config\      (your local settings)
    data\


============================================================
 STEP 2 — EXTRACT
============================================================
Extract SaaSVersion_V143.zip over the same folder (D:\MOB).
If Windows asks "Replace or Skip", choose REPLACE for all.


============================================================
 STEP 3 — VERIFY (search string per file)
============================================================
Open each file in Notepad, press Ctrl+F, search for the string shown.
Not found = that file did not get replaced. Delete it and extract again.

  FILE                          SEARCH FOR (Ctrl+F)
  ----------------------------  -----------------------------------
  VERSION                       V143
  tools\native_helper.ps1       function Invoke-Native
  tools\start_offline.ps1       $__nh = Join-Path
  tools\install_offline.ps1     $__nh = Join-Path
  tools\resolve_php.ps1         $__nh = Join-Path
  tools\resolve_mariadb.ps1     $__nh = Join-Path
  tools\fix_vcruntime.ps1       $__nh = Join-Path

That "$__nh = Join-Path" line is the new guard — it is what lets each
script keep working even when the helper file is missing.

Check all of them at once —
RUN THIS ON THE OFFLINE PC, in Command Prompt (CMD), in the package folder:

    findstr /M /C:"$__nh = Join-Path" tools\*.ps1

You should see five file names:
    tools\fix_vcruntime.ps1
    tools\install_offline.ps1
    tools\resolve_mariadb.ps1
    tools\resolve_php.ps1
    tools\start_offline.ps1

And confirm the helper itself arrived:

    dir tools\native_helper.ps1


============================================================
 STEP 4 — START
============================================================
ON THE OFFLINE PC: double-click START_OFFLINE.bat.

The software should open normally. If this PC never finished the V142
setup because of the Visual C++ error, run INSTALL_OFFLINE.bat first —
right-click it and choose "Run as administrator".


============================================================
 FOR YOU, ON THE CLOUD SIDE (Railway)
============================================================
The packager fix is in public/api.php, so every offline package built
after you deploy V143 carries the complete tools\ folder. Deploy V143 to
Railway before you send any customer a fresh offline download.

After deploying, the boot log will show:

    OFFLINE_TOOLS_OK

If it ever shows OFFLINE_TOOLS_FAIL, a script is referencing a file that
would not be in the package — same shape as this bug, caught before it
reaches a customer.

You can also run it by hand on the server shell:

    php scripts/check_offline_tools.php


============================================================
 STILL NOT STARTING?
============================================================
ON THE OFFLINE PC, in Command Prompt (CMD), in the package folder:

    powershell -NoProfile -ExecutionPolicy Bypass -File tools\diagnose.ps1

Send us that output.

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
------------------------------------------------------------

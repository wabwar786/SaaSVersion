============================================================
 V145 — ONE-CLICK UPDATE
 Ab customer ko offline version DOBARA download nahi karna parega
 Wabwar Software House
============================================================

AAP KO DO FILES MILI HAIN
-------------------------
1) SaaSVersion_V145.zip        -> Railway par deploy (server)
2) UPDATE_PATCH_V145.zip       -> 9 KB; customer ko ye bhejni hai


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    WHATS_UPDATED.md
    INSTALL_UPDATE.bat
    GET_UPDATE.bat
    tools\docker-entrypoint.sh

    Ye NAYI hain, delete karne ki zaroorat nahi:
    UPDATE.bat, tools\update.ps1, scripts\migration_order.php,
    scripts\run_migrations.php, tools\build_update_patch.php

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V145.zip extract karein, phir Railway par upload/deploy.

STEP A3 — DEPLOY LOG CHECK (Railway dashboard -> Deploy log)
    Ye aana chahiye:

        MIGRATIONS_DONE stage=boot ok=30 skipped=0 failed=0
        MIGRATIONS_OK
        OFFLINE_TOOLS_OK
        MODULE_GROUPS_OK
        [boot] starting Apache
        MIGRATIONS_DONE stage=background ok=3 skipped=0 failed=0

    Agar "!! MIGRATION_NOT_LISTED" dikhe to matlab koi migration file
    hai jo migration_order.php mein nahi — mujhe batayein.

STEP A4 — VERIFY (search string per file)
    Notepad mein kholein, Ctrl+F:

      FILE                            SEARCH FOR (Ctrl+F)
      ------------------------------  ---------------------------
      VERSION                         V145
      scripts\migration_order.php     'script' => 'install_schema'
      scripts\run_migrations.php      MIGRATIONS_DONE
      tools\update.ps1                param([ValidateSet('check'
      tools\docker-entrypoint.sh      run_migrations.php --stage=boot
      UPDATE.bat                      tools\update.ps1
      GET_UPDATE.bat                  tools\update.ps1
      INSTALL_UPDATE.bat              tools\update.ps1
      tools\build_update_patch.php    PATCH_OK


============================================================
 PART B — CUSTOMER (offline PC) — EK DAFA ka kaam
============================================================
Customer ko SIRF  UPDATE_PATCH_V145.zip  bhejein (9 KB, WhatsApp/email
se bhi chali jayegi). Offline version dobara bhejne ki zaroorat NAHI.

Customer ye kare:

  1. Software band kar de.
  2. Zip ki saari files apne software ke folder mein copy kare
     (wahi folder jahan START_OFFLINE.bat hai, misal D:\MOB).
     Windows poochega to "Replace the files" chune.
  3. UPDATE.bat par double-click.

Bas. Wo khud: check -> download -> backup -> nayi files -> database.

AAGE SE: sirf UPDATE.bat. Hamesha.


============================================================
 TEEN BUTTON — KYA FARQ HAI
============================================================
  UPDATE.bat          sab kuch ek click mein   <- yehi istemal karwayein
  GET_UPDATE.bat      sirf download (install baad mein)
  INSTALL_UPDATE.bat  jo download ho chuka hai wo lagana

Teeno ek he file chalati hain: tools\update.ps1


============================================================
 KYA THEEK HUA
============================================================
1) DATABASE ka qadam adhoora tha.
   INSTALL_UPDATE.bat sirf 5 migrations chalati thi, cloud 33 chalata
   hai. Yani nayi table/column customer tak CODE ki shakal mein pohanch
   jati thi magar SCHEMA ke baghair — software update ho kar toot jata.

   Ab dono taraf ek he fehrist chalti hai:
       scripts\migration_order.php   (33 steps, tarteeb ke sath)
       scripts\run_migrations.php    (chalata hai)

   Nayi migration add karni ho to EK line, EK file. Cloud aur offline
   dono par khud chal jayegi. Aur agar koi migrate_*.php list mein daalna
   bhool jayen to runner khud shor machata hai.

2) INSTALL_UPDATE.bat KHUD KO chalte hue overwrite kar leti thi.
   Wo zip ke andar bhi hai aur extract loop usay replace kar deta tha.
   cmd.exe batch file ko line-by-line parhta hai — khud ko badalna
   bigaar paida karta hai. Ab saara kaam tools\update.ps1 mein hai
   (PowerShell poori script pehle memory mein parhta hai), aur .bat
   sirf 3 line ka stub hai jo kabhi badlega nahi.

3) Do qadam thay. Ab UPDATE.bat se ek.


============================================================
 CUSTOMER KA DATA
============================================================
Sirf program ki files badalti hain. Ye NAHI chhuee jatin:
    data\      config\      storage\
    runtime\php      runtime\mariadb      runtime\data

Har update se pehle purani files backup\before-<date> mein chali jati
hain. Migration fail ho to update ruk jata hai aur wahi backup ka
rasta batata hai.


============================================================
 MASLA AAYE TO
============================================================
Customer ke PC par, software ke folder mein, CMD kholein:

    powershell -NoProfile -ExecutionPolicy Bypass -File tools\diagnose.ps1

Us ka output bhej dein.

Apni source copy se patch dobara banana ho to (local PC / server shell):

    php tools\build_update_patch.php

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
------------------------------------------------------------

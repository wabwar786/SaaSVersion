============================================================
 V144 — deploy log ka "FUNCTION railway.MD5 does not exist"
 Wabwar Software House
============================================================

PEHLE YEH SAMAJH LEN
--------------------
V143 ka deploy THEEK hua tha. Ye dono green thay:

    MODULE_GROUPS_OK
    OFFLINE_TOOLS_OK

Offline package download karna ab bhi mehfooz hai. V144 us EK line ka
ilaj hai jo deploy log mein fail ho rahi thi.


KYA FAIL HO RAHA THA
--------------------
    PLATFORM_MODULES_READY total=39 added=0
    SQLSTATE[42000]: 1305 FUNCTION railway.MD5 does not exist
    [boot] module ids migrate skipped

migrate_module_ids.php ki AAKHRI line ek SQL query thi jo MD5() use
karti thi. Railway ke server par MD5 resolve nahi hua, exception catch
nahi hui, aur poori migration non-zero par khatam ho gayi.

Ahem: us migration ka ASAL kaam pehle he ho chuka tha (39 ke 39 module
ids canonical thin). Sirf aakhri line mari. Is se kuch toota nahi.


IS SE BARA MASLA JO SAATH MILA
------------------------------
Wohi query Sync::moduleFingerprint() mein bhi thi — wohi value jo cloud
aur har offline computer ke beech compare hoti hai (module ids match
karti hain ya nahi).

GROUP_CONCAT khamoshi se KAAT deta hai. 39 modules par string ~2.3 KB
hai, magar:

    MySQL 8  (Railway, cloud)   group_concat_max_len = 1024   -> KAAT
    MariaDB  (offline node)     group_concat_max_len = 1048576 -> poori

Yani BILKUL ek jaise module ids se cloud aur node DO ALAG fingerprint
bana rahe thay. Na error, na warning. Diagnostics hamesha "MISMATCH"
dikhati rahi — jo kabhi asli tha he nahi.

Sirf ek diagnostic check hai, sync rukti nahi — is liye is se bhi kuch
toota nahi. Magar wo check be-kaar tha, aur agar kabhi ASLI mismatch
hota to chhup jata.

HAL: fingerprint ab PHP mein banta hai, SQL mein nahi. Na koi DB
function, na koi length limit. Har MySQL/MariaDB par ek he jawab.


============================================================
 AAP KO KYA KARNA HAI
============================================================

A) SERVER (Railway) — yahi asal cheez hai
   1. V144 upload / deploy karein.
   2. Deploy log mein ye teen dekhein:

          MODULE_IDS_READY moved=0 merged=0 already_ok=39 failed=0
          MODULE_FINGERPRINT <32 characters>
          MODULE_GROUPS_OK
          OFFLINE_TOOLS_OK

      "module ids migrate skipped" ab NAHI aana chahiye.

B) OFFLINE PCs — kuch karna zaroori NAHI
   Agar aap waise bhi update bhej rahe hain, to neeche STEP 1-3.
   Warna chhor dein; ye fix server side ka hai.


============================================================
 OFFLINE PC PAR UPDATE (agar bhej rahe hain)
============================================================

STEP 1 — PEHLE YE PURANI FILES DELETE KAREIN
    (Windows ka "Replace or Skip" files skip kar deta hai)

    VERSION
    WHATS_UPDATED.md

    Baqi sab V143 wala he hai — tools\ folder ko haath lagane ki
    zaroorat nahi.

    Ye NA chhuein:  runtime\   storage\   config\   data\

STEP 2 — EXTRACT
    SaaSVersion_V144.zip usi folder par extract karein (D:\MOB).
    "Replace or Skip" aaye to REPLACE all.

STEP 3 — VERIFY (har file ke liye search string)
    Notepad mein kholein, Ctrl+F:

      FILE                           SEARCH FOR (Ctrl+F)
      -----------------------------  ------------------------------
      VERSION                        V144
      src\Services\Sync.php          md5(\implode('|', $parts))
      scripts\migrate_module_ids.php Sync::moduleFingerprint()
      public\api.php                 Sync::moduleFingerprint();

    NOTE: offline package mein src\ aur scripts\ seal (app.sealed) ke
    andar hote hain, is liye wahan ye files alag se nazar nahi
    aayengi — ye search strings SOURCE copy ke liye hain (jo aap
    GitHub / Railway par rakhte hain).

    Offline PC par bas itna dekh lein:

        type VERSION

    V144 likha aana chahiye.


============================================================
 DEPLOY LOG KI BAQI LINES — SAB NORMAL HAIN
============================================================
Ye teen fikar ki baat NAHI:

  "SCHEMA_INSTALLED ... failed=3"
  "Duplicate foreign key constraint name 'fk_ii_...'"
  "Duplicate entry 'V1' for key 'schema_migrations.PRIMARY'"
        -> Database pehle se bana hua hai, migration dobara chali.
           Jo cheezein pehle se mojood hain unhein dobara banane ki
           koshish hui. Kuch kharab nahi hua.

  "AH00558: Could not reliably determine the server's fully qualified
   domain name"
        -> Apache ka aam notice. Ignore.

EK CHEEZ JO KARNI CHAHIYE:
  Log mein ye bhi aaya:
      SKIPPED: this reset was already applied
      (SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD variables ab hata dein.)

  Railway dashboard -> Service -> Variables mein ja kar ye DO variables
  delete kar dein. Jab tak ye mojood hain, har deploy super-admin ka
  password reset kar sakta hai.

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
------------------------------------------------------------

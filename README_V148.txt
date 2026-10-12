============================================================
 V148 — TAX SAVE HO JATA HAI (aur "till par tax" ka matlab)
 Wabwar Software House
============================================================

AAP KA SAWAL 1: "tax added at the till ka matlab yeh hai k item ki
price main alag se tax lagy ga??"

  JI HAAN. Do shakalein hain:

    Tax included in the price   100 ka item, 16% tax
                                -> 86.21 net + 13.79 tax
                                -> customer deta hai 100

    Tax added at the till       100 ka item, 16% tax
                                -> 100 net + 16 tax
                                -> customer deta hai 116

  Yani "added at the till" mein tax price ke UPAR se lagta hai aur
  Grand Total barh jata hai.

  Aap ki screen par jo ajeeb baat thi: upar "Tax added at the till"
  likha tha magar bill par "PKR 730 (8% Card, incl.)" aa raha tha aur
  Grand Total subtotal ke barabar tha. Wajah neeche — us panel ka
  apna alag Save button tha.


AAP KA SAWAL 2: "tax main ny 0 keya hai to save nahi ho rha"

  Masla 0 ka nahi tha — KOI BHI value save nahi hoti thi. Hum ne is ko
  dobara paida kar ke dekha:

      save tax_cash = 0   ->   ok = true,  jawab mein 16
      page reload         ->   16, 8                (koi error nahi)

  ASLI WAJAH
  Tax rates `ui_records` par thin. Us table par koi unique key nahi,
  is liye EK SITE KI DO ROW ban sakti thin — aur banti thin: branch
  computer apni rows cloud par bhejta hai aur us row ka id alag hota
  hai, to cloud par doosri copy ban jati thi. Phir:

      PADHNE  wala code:  ORDER BY created_at DESC  ->  NAYI row
      LIKHNE  wala code:  (koi order nahi)          ->  PURANI row

  Save purani row par lagta, screen nayi row dikhati. Hamesha.


============================================================
 V148 MEIN KYA THEEK HUA
============================================================

1) TAX AB EK HI ROW PAR
   Rates ab `site_settings` par hain jahan UNIQUE (site, group, key)
   laga hua hai — doosri row ban hi nahi sakti. Purani row pehli dafa
   parhte waqt khud uthai jati hai aur band kar di jati hai.
   0 save hota hai. 16 save hota hai. Reload par wahi rehta hai.

2) EK HI "SAVE CHANGES" BUTTON
   Price mode (inclusive / exclusive) ka apna alag "Save price mode"
   button tha. Log upar wala Save dabate, "Settings saved" aata, aur
   price mode purana reh jata — isi liye bill par "(incl.)" chhapta
   tha. Wo alag button hata diya; ab sab kuch ek hi button se.

3) SETTINGS AB BRANCH TAK POHANCHTI HAIN
   `site_settings` na push list mein thi na pull mein. Yani portal par
   tax / price mode / currency / rounding / receipt ki header-footer
   badlo — branch computer ko kabhi pata hi na chalta. Ab dono taraf
   sync hoti hain.
   FBR ka access key NAHI jata (secret rows ruk jati hain).
   PURANE NODES BHI: yeh code mein daala gaya hai, config mein nahi —
   is liye customer sirf UPDATE.bat chalaye, offline version dobara
   download karne ki zaroorat NAHI.

4) EK HI JAGAH SE LIKHAI (taake yeh dobara na ho)
   `site_settings` par PAANCH files apni apni SQL chala rahi thin.
   Ab sirf `SiteSetting.php`. Aur ek checker is baat ka pehredaar hai:
       php scripts/check_settings_single_row.php
   Deploy par khud chalta hai — SETTINGS_CHECK_OK.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    VERSION
    config\local.php
    public\api.php
    src\helpers.php
    src\Services\SettingsService.php
    src\Services\TaxMode.php
    src\Services\Sync.php
    src\Services\Licence.php
    src\Services\BackupService.php
    src\Services\WhatsApp.php
    scripts\migration_order.php
    approved_ui\settings.html

    YE NAYI HAIN — delete karne ki zaroorat nahi:
    src\Services\SiteSetting.php
    scripts\migrate_tax_settings.php
    scripts\check_settings_single_row.php

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V148.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        MODULE_GROUPS_OK
        SETTINGS_CHECK_OK
        TAX_SETTINGS_OK          <- nayi (sites=.. moved=.. retired=..)
        OFFLINE_TOOLS_OK

STEP A4 — VERIFY (har file ke liye search string)
    Notepad mein kholein, Ctrl+F:

      FILE                                  SEARCH FOR (Ctrl+F)
      ------------------------------------  --------------------------
      VERSION                               V148
      src\Services\SiteSetting.php          setting_uuid
      src\helpers.php                       function setting_uuid
      src\Services\SettingsService.php      adoptLegacyTaxes
      src\Services\TaxMode.php              SiteSetting::put
      src\Services\Sync.php                 'site_settings',
      public\api.php                        SettingsService::saveTaxes
      approved_ui\settings.html             data-s="tax_mode"
      scripts\migrate_tax_settings.php      TAX_SETTINGS_OK
      scripts\migration_order.php           check_settings_single_row

STEP A5 — BROWSER MEIN CHECK (business mein login kar ke)
    Settings kholein:
      - "Save price mode" ka alag button ab NAZAR NAHI AANA CHAHIYE
      - Cash tax 0 karein -> Save changes -> page refresh -> 0 hi rahe
      - Price mode "Tax added at the till" -> Save changes
      - Sale Point kholein: ab tax price ke UPAR lagega aur
        Grand Total barh jayega (pehle "(incl.)" aata tha)


============================================================
 PART B — OFFLINE CUSTOMERS
============================================================
Kuch bhejne ki zaroorat NAHI. Deploy ke baad customer sirf

    UPDATE.bat

chalaye. V147 wala UPDATE_PATCH kaafi hai — is version mein .bat aur
tools\update.ps1 mein koi tabdeeli nahi hui.

Update ke baad pehli sync par portal ki tax settings branch par khud
utar aayengi.


============================================================
 TESTING JO KI GAYI
============================================================
  - 96 screens (54 restaurant + 42 retail) asli browser mein khulin:
    sab HTTP 200, koi JavaScript error nahi
  - tax 0 / 16 / 100 se zyada — save, reload, POS tak teeno raaste
  - purani do-row wali soorat banayi gayi: ab bhi value nahi badalti
  - purani pos_settings row khud utha kar band hone ka test
  - price mode EXCLUSIVE -> POS boot tak pohanchta hai (verify)
  - check_settings_single_row ko jaan-boojh kar torha gaya — pakar
    leta hai, phir theek kar ke OK

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
 V148 build 2026-10-12
------------------------------------------------------------

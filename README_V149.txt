============================================================
 V149 — CHHAY CHEEZEIN
 Wabwar Software House
============================================================

1) MENU SE SEEDHA INVENTORY — "do do baar" ka kaam khatam
   Aap ne theek kaha tha. Ab Menu & Categories par:

     "Set up stock"      -> poore menu ka inventory ek click mein
     "Make stock item"   -> sirf us ek item ka (jis par "No stock" likha hai)

   Har item usi naam se inventory mein ban jata hai aur jur bhi jata
   hai (1 unit per sale). Phir Edit ke dropdown mein bhi aa jata hai.

   Dobara dabana MEHFOOZ hai:
     - usi naam ka inventory item pehle se ho to NAYA nahi banta,
       wahi jor diya jata hai;
     - jin ka stock pehle se set hai (khaas kar RECIPE wale) un ko
       bilkul haath nahi lagta.

2) ITEM DIALOG KA KHALI DROPDOWN
   Aap ke business mein koi inventory item tha hi nahi, is liye
   dropdown mein sirf "— choose —" aata tha aur badalta hi nahi tha.
   (Hum ne test kiya: jahan items mojood hain wahan chunna aur save
   dono theek chal rahe thay.)

   Ab:
     - koi inventory item na ho to khali picker dikhta hi nahi —
       seedha "yeh item khud stock banega" wala khana khulta hai;
     - BAND (inactive) items bhi list mein aate hain, "· inactive"
       likha hua;
     - link toota ho (inventory item mit gaya ho) to saaf likha aata
       hai, khamoshi se "— choose —" par nahi girta.

3) BRANCH KA NAAM — AB DB SE
   "Islamabad — F10" chaar jagah hard-coded tha: dono shell.js,
   dashboard.html aur index.html. Ab `sites.name` se aata hai, ek hi
   jagah se (router), dono verticals par.

4) SUPER ADMIN: SETTINGS KA APNA SWITCH
   Pehle Settings, Activate/Renew aur Backup teeno ek hi `settings`
   key par thay — Settings band karte hi customer ka RENEW ka darwaza
   bhi band ho jata (aur licence khatam hone par wo kuch bhi na kar
   paata).

   Ab teen alag switch:
     Settings          -> hide ho sakta hai
     Activate / Renew  -> HAMESHA ON (hide nahi ho sakta)
     Backup & Restore  -> apna switch

5) FEATURES POPUP KI ALIGNMENT
   Do wajah thin:
     - `shared.css` ka `input{width:100%;height:40px}` heading wale
       checkbox par chal raha tha (us ka apna CSS tha hi nahi) — wo
       40px ka neela chakor ban kar heading ko dhakel deta tha;
     - `.mf-wrap` flex column hai `max-height:52vh` ke sath, aur
       `.mf-grp` par `flex:0 0 auto` nahi tha — groups dab jate aur
       `overflow:hidden` rows ko kaat deta. Yehi overlap tha.
   Dono theek. Heading ka checkbox ab chhota aur seedha hai.

6) EXPIRY KA POORA FLOW — CHAAR SOORAAKH BAND
   a) Cloud par expiry SIRF LOGIN par dekhi jati thi. Session 12
      ghante ka hai, is liye jo cashier pehle se andar tha wo expiry
      ke baad bhi poora din bills kaat sakta tha. Ab har request par
      (offline ki tarah) — aur wo seedha Activate screen par jata hai.
   b) Cloud par expire hone ke baad koi renew screen nahi thi, sirf
      ek message. Ab wahi Activate screen (payment + "humein bata
      dein"). Key wala khana sirf offline par dikhta hai.
   c) `scripts/licence_enforce.php` SIRF pehli install par chalti thi.
      Yani 30 din ka grace khatam hone ka hissa likha hua tha magar
      KABHI CHALA HI NAHI. Ab har start par aur har update par.
   d) Super Admin "Activate" dabata to status ACTIVE ho jata magar
      tareekh guzri hui rehti — customer phir bhi block, aur dono
      taraf koi paighaam nahi. Ab saaf mana kar deta hai aur nayi
      tareekh maangta hai. Renew par bhi guzri hui tareekh rok di
      gayi hai.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    VERSION
    public\api.php
    public\router.php
    public\shell.js
    public\item_dialog.js
    src\Auth.php
    src\Services\ModuleGroups.php
    src\Services\MenuItemService.php
    scripts\migration_order.php
    scripts\seed_industry_modules.php
    scripts\seed_platform_modules.php
    tools\start_offline.ps1
    approved_ui\super_admin.html
    approved_ui\menu_management.html
    approved_ui\activate.html
    approved_ui\index.html
    approved_ui\dashboard.html
    approved_ui\retail\shell.js

    (Is version mein koi nayi file nahi hai.)

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V149.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        MODULE_GROUPS_OK
        SETTINGS_CHECK_OK
        OFFLINE_TOOLS_OK
        PLATFORM_MODULES_READY        <- ab 42 (activate + backup naye)

STEP A4 — VERIFY (har file ke liye search string)
    Notepad mein kholein, Ctrl+F:

      FILE                                  SEARCH FOR (Ctrl+F)
      ------------------------------------  --------------------------
      VERSION                               V149
      public\router.php                     window.APP_SITE
      public\shell.js                       function siteName
      approved_ui\retail\shell.js           function siteName
      public\item_dialog.js                 no longer exists
      public\api.php                        menu-stock-setup
      src\Services\MenuItemService.php      function stockSetup
      src\Services\ModuleGroups.php         'dashboard', 'activate'
      src\Auth.php                          ALWAYS_ON kabhi gate se
      approved_ui\menu_management.html      mmStock
      approved_ui\super_admin.html          flex:0 0 auto
      approved_ui\activate.html             APP_ROLE==='cloud'
      scripts\migration_order.php           licence_enforce
      tools\start_offline.ps1               licence_enforce.php

STEP A5 — BROWSER MEIN CHECK (business mein login kar ke)
    - Sidebar ke upar ab ASLI branch ka naam (Settings mein jo likha hai)
    - Menu & Categories -> "Set up stock" button; jis item par
      "No stock" ho us par "Make stock item"
      -> dabane ke baad Edit kholein: dropdown mein wahi item mojood
    - Super Admin -> Features: SYSTEM ke neeche Settings, Activate /
      Renew (locked), Backup & Restore — teen alag
      -> Settings band kar ke customer ka menu dekhein: Settings gayab,
         Activate / Renew mojood
    - Features popup: koi row overlap nahi, heading ka checkbox chhota


============================================================
 PART B — OFFLINE CUSTOMERS
============================================================
Customer sirf

    UPDATE.bat

chalaye. Koi nayi file bhejne ki zaroorat nahi — is version mein
.bat aur tools\update.ps1 mein koi tabdeeli nahi hui.

Update ke baad har start par licence bhi check hoti hai (grace ke
andar kuch nahi mitta; sirf dinon ki ginti nazar aati hai).


============================================================
 TESTING JO KI GAYI
============================================================
  - 96 screens (54 restaurant + 42 retail) asli browser mein: sab
    HTTP 200, koi JavaScript error nahi
  - "Set up stock": 7 pending -> 7 naye inventory items, 18/18 link,
    recipe wala item chhua tak nahi; dobara dabaya — kuch na badla
  - Item dialog: saare inventory items hata kar dekha — khali picker
    ke bajaye "yeh item khud stock banega" wala khana khulta hai
  - Branch ka naam DB mein badal kar dekha — sidebar aur dashboard
    dono par foran badal gaya
  - Super Admin se Settings band: menu se gayab, URL se kholne par
    bhi rok; Activate / Renew aur Backup chalte rahe
  - Expiry: login ke BAAD licence khatam ki — wahi session agli
    request par Activate screen par chala gaya; guzri hui tareekh par
    Activate aur Renew dono ne mana kiya; sahi tareekh par foran
    bahaal
  - tools\*.ps1 ka syntax PowerShell 7.4.6 se check kiya

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
 V149 build 2026-10-12
------------------------------------------------------------

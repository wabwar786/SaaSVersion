============================================================
 V166 — ITEMS AUR CATEGORIES SELECT KAR KE EK SATH DELETE
 Wabwar Software House                     build 2026-10-25
============================================================

Menu screen par ab har item ke saamne ek CHECKBOX hai. Jo chahein chun
lein aur ek hi dafa hata dein — admin (ya manager) ka password de kar.

Pehle har item par alag Delete dabana parta tha. Akorwal ka menu saaf
karne ke liye 49 items + 17 categories = 66 dafa confirm. Ab ek dafa.

Wahi cheez "Category order" wale dialog mein bhi.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
CHE FILES BADLIN (README ke ilawa), KOI NAYI FILE NAHI:

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui/menu_management.html
    public/api.php
    public/delete_kit.js
    public/router.php

KOI MIGRATION NAHI — database ko kuch nahi hota.


KAISE CHALEGA
  1. Menu & Categories kholein
  2. Jin items ko hatana hai un ke checkbox lagayein
     (sab ke liye: upar heading wala checkbox — wo SIRF wahi chunta
      hai jo us waqt screen par nazar aa rahe hain, yani search aur
      category filter ke baad wale)
  3. Upar peeli patti aa jati hai: "4 items selected" + Delete selected
  4. Delete selected -> naam ki list, admin/manager ka password, aur
     chahein to reason (audit log ke liye)
  5. Natija saaf: "4 deleted" — ya agar koi na hua to us ki WAJAH

CATEGORIES
  "Category order" dialog mein bhi wahi checkbox aur wahi patti.

  EK SHART YAAD RAKHEIN: jis category ke andar item para ho wo delete
  NAHI hoti (server inkar karta hai — yeh pehle se hai). Is liye dialog
  mein ek option hai:

      [x] Inside items bhi delete karein

  Yeh lagayen to pehle us category ke items jate hain, phir category
  khud. Poora menu ek hi dafa saaf karne ka yehi raasta hai.
  Bina is ke tarteeb yeh hai: pehle items, phir categories.


------------------------------------------------------------
 MEHFOOZ KYUN HAI
------------------------------------------------------------
1. PASSWORD HAR DAFA — ADMIN KO BHI.
   Akele record par admin se password nahi maanga jata (wo malik hai).
   Magar yahan ek click se pachas record ja sakte hain. Counter par
   khula POS chhor dena aam baat hai — yeh password us darmiyan khara
   hai. Cashier bhi yeh kaam kar sakta hai, magar sirf admin ya manager
   ka password de kar.

2. SIRF MENU. Server par entity ki choti si fehrist hai: menu item aur
   menu category. Koi aur cheez (user, bill, stock) bhejne par server
   saaf inkar kar deta hai. Bulk delete taqatwar cheez hai; usay har
   jagah khol dena theek nahi.

3. DELETE WAHI PURANA "SOFT" DELETE HAI.
   PURANE BILL BILKUL SALAMAT REHTE HAIN. Item row apni jagah rehti
   hai, sirf chhup jati hai — har purana bill waise ka waisa chhapta
   hai. Sirf aage bikna band ho jata hai.

4. EK DAFA MEIN 500 KI HADD. Is se zyada par server inkar karta hai,
   aur software khud 100-100 ke tukron mein bhejta hai. Warna PHP ka
   waqt khatam hone par aadha kaam hota aur kisi ko pata na chalta.

5. HAR DELETE AUDIT LOG MEIN JATA HAI — jaisa pehle se hota aaya hai.


------------------------------------------------------------
 BANATE WAQT DO KHARABIYAN PAKRI GAYIN (DONO THEEK KAR DIN)
------------------------------------------------------------
1. GHALAT PASSWORD PAR POS BAND HO JATA THA.
   Server ghalat password par 401 bhejta tha. Aur `db_api.js` mein
   likha hai: jo bhi jawab 401 ho, usay "session khatam" samajh kar
   seedha login screen par bhej do. Yani password mein ek harf ki
   ghalti par chalta hua kaam gaya aur dobara login karna para.

   Ab 422 jata hai: "Incorrect admin or manager password" screen par
   nazar aata hai aur user apni jagah khara rehta hai.

2. INKAR KI WAJAH ADHOORI AA RAHI THI.
   Server BLOCKED ke sath do cheezein bhejta hai: ek sarnama
   ('Menu category "Rice" cannot be deleted:') aur asal wajah alag.
   Pehle sirf sarnama aa raha tha — jo kuch batata hi nahi. Ab poori
   wajah: 'this category has 3 item(s) - move or remove them first'.


EK PURANI KHARABI JO NAZAR AAYI — ABHI THEEK NAHI KI
   Wahi 401 wala masla "Void bill" aur "Force delete" par bhi maujood
   hai: ghalat manager password par user login screen par chala jata
   hai. Yeh aap ki farmaish mein nahi tha is liye haath nahi lagaya.
   Kahein to agli dafa theek kar doon — do line ka kaam hai.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
  PASS 46 / FAIL 0

  Server (18 test):
    Password ke baghair -> inkar                                    OK
    Ghalat password -> inkar, aur kuch delete NAHI hota             OK
    entity 'user' / 'order' -> saaf inkar                           OK
    Khali list -> inkar                                             OK
    501 ids -> inkar                                                OK
    Bina login -> inkar                                             OK
    3 items delete -> ginti theek, koi nakaam nahi                  OK
    Items wali category -> BLOCKED, poori wajah ke sath             OK
    Khali category -> delete ho gayi                                OK
    Cashier + admin ka password -> chal gaya                        OK
    Cashier + ghalat password -> inkar                              OK
    Delete ke baad purane bill aur un ki lines jyun ke tyun         OK

  Browser (28 test) — asli Menu screen par:
    Har row par checkbox, shuru mein patti chhupi                   OK
    Select karne par patti, ginti, aur rows numaya                  OK
    Clear se sab saaf                                               OK
    Heading wala checkbox = jo nazar aa rahe hain sab                OK
    Filter badalne par chhupe hue items ka select khud hat jata hai OK
    Dialog mein ginti, naam ki list, yaad dehani                    OK
    Ghalat password: kuch delete nahi hua, wajah screen par         OK
    Sahi password: items gaye, list refresh, patti chhup gayi       OK
    Category dialog: checkbox, patti, cascade ka option             OK
    Cascade band: BLOCKED ki wajah nazar aayi, category bachi       OK
    Cascade chalu: andar ke items bhi gaye, category bhi            OK
    Koi JS error nahi                                               OK


============================================================
 LAGANE KA TAREEQA
============================================================

PART A — OFFLINE PC

STEP A1 — BACKUP
    Purani copy sambhaal lein (ya V165 ka zip rakha rehne dein).

STEP A2 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\menu_management.html
    public\api.php
    public\delete_kit.js
    public\router.php

    (Windows "Replace or Skip" poochta hai aur aksar Skip ho jata hai
     — isi liye pehle delete karna zaroori hai.)

    KOI NAYI FILE NAHI (README ke ilawa).

STEP A3 — EXTRACT
    SaaSVersion_V166.zip extract karein, phir UPDATE.bat

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                             SEARCH FOR
      -------------------------------  -------------------------
      VERSION                          V166
      public\api.php                   entity-delete-bulk
      public\delete_kit.js             confirmMany
      public\router.php                delete_kit.js?b=v166
      approved_ui\menu_management.html data-pick
      approved_ui\menu_management.html data-cpick
      approved_ui\menu_management.html mmSelDel

STEP A5 — CHALA KAR DEKHEIN
    1. Menu & Categories kholein
    2. Do item select karein -> upar patti aani chahiye
    3. Delete selected -> password maange -> ghalat password dein:
       "Incorrect admin or manager password" aana chahiye, aur aap ko
       login screen par NAHI phenkna chahiye
    4. Sahi password dein -> dono items hat jayen
    5. Koi purana bill reprint kar ke dekh lein — waisa ka waisa


PART B — SERVER (Railway)

    Wahi files, wahi tarteeb. Deploy kar dein.
    Menu screen cloud par bhi isi tarah chalegi.


------------------------------------------------------------
 AKORWAL KA MENU SAAF KARNE KA CHHOTA TAREEQA
------------------------------------------------------------
    1. Menu & Categories -> heading wala checkbox -> Delete selected
       -> admin password  (saare items ek dafa mein)
    2. Category order -> upar wala... har category select karein
       -> Delete selected -> admin password
    3. Agar koi category reh jaye to us mein abhi item hai — dialog
       mein "Inside items bhi delete karein" laga kar dobara karein

    YEH KAAM OFFLINE PC PAR KAREIN. Sync khud cloud tak le jati hai.
    Aakhir mein cloud wale menu par ek nazar daal lein: agar wahan koi
    aisi cheez pari ho jo offline PC par thi hi nahi, wo agle pull par
    wapas aa jati hai — usay wahan se hata dein.

Madad: Wabwar Software House — +92 342 5095104

// build: V166 build 2026-10-25

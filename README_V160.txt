============================================================
 V160 — OWNER APP (malik ka mobile portal)
 Wabwar Software House                     build 2026-10-20
============================================================

Malik ke liye alag phone portal. Super admin se, ek ek property ko.
Baqi kisi cheez ko haath nahi lagaya: POS, bill, KOT, stock, reports,
sync — sab V159 jaise hain.


------------------------------------------------------------
 YEH HAI KYA
------------------------------------------------------------
Malik ke phone ki screen: aaj kitna bana, kya bik raha hai, godaam
mein abhi kya hai, aur kis ka account khula para hai.

Cashier ki screen se alag soch par bani hai. Cashier ka sawal hai
"abhi kya karna hai", malik ka sawal hai "aaj kitna BACHA" aur
"kahan dhyan dena hai".

YEH SCREEN KUCH BADAL NAHI SAKTI. Na bill, na rate, na stock ki qty.
Sirf ek cheez: kisi item par sitara (nazar mein rakhna). Malik apna
phone kisi ko pakra de to bhi nuqsaan ka rasta nahi.

APP STORE KI ZAROORAT NAHI. Phone ke browser mein link khol kar
"Add to Home Screen" — icon lag jata hai aur app ki tarah khulta hai.


------------------------------------------------------------
 AAP ISE KAISE DETE HAIN
------------------------------------------------------------
  Super Admin -> All Businesses -> us property ki row -> "Owner app"

Dialog mein status nazar aata hai. "Turn on" dabate hi LINK ban jata
hai, aur "Copy" us ke saath hai — malik ko WhatsApp par bhej dein:

      https://aap-ka-pata/m?b=business-ka-slug

LINK MEIN KOI TOKEN NAHI — wo sirf DARWAZA hai, chaabi nahi. Kholte
hi login maanga jata hai, bilkul baqi software ki tarah. Link kisi
aur ke haath lag jaye to us ke paas user aur password nahi hoga.

`?b=` sirf yeh batata hai ke kis business ka login kholna hai.

TEEN PEHRE, ISI TARTEEB SE:
  1. Login hai?                      nahi -> login par, usi business ka
  2. Aap ne is property ko diya hai? nahi -> 403
  3. Malik ya manager hai?           nahi -> 403

Cashier ke paas user-password ho to bhi yeh screen nahi khulti: is
par poore business ka munafa aur sab ka hisaab hai.

CHALU KARNE SE BAQI MODULES BAND NAHI HOTE. Jin businesses par koi
module-hadd set nahi thi (yani sab allowed), un par pehle poori list
banti hai, phir us mein yeh ek cheez jurti hai. (Yeh wahi ehtiyat hai
jo FBR wale switch mein hai.)

AHEM — YEH BAAKI MODULES JAISA NAHI HAI. Baqi ka qanoon yeh hai ke
"features khali ho to sab allowed", yani wo khud ba khud har jagah
chal parte hain. Owner app us tarah nahi khulta: khulta SIRF tab hai
jab aap saaf dein. Isi liye `owner_mobile` ko `platform_modules` mein
bhi darj nahi kiya — warna "sab modules" wali list banate waqt wo
khud shamil ho jata aur yehi pehra bekar ho jata.


------------------------------------------------------------
 CHAAR SCREEN
------------------------------------------------------------
AAJ
  Aaj ki sale, aur "kal IS WAQT TAK" se moqabla — poore kal se nahi.
  (Dopahar 2 baje aaj ki aadhi sale ko kal ke poore din se milana
  hamesha "kam" dikhata hai, chahe aaj behtar ja raha ho.)
  Saath: bills, average bill, discount, abhi kitne order chal rahe,
  paisa kahan se aaya, kis ghante kitna, aaj sab se ziada, kis ne
  kitna becha. Lagat aur kharcha darj hon to poora hisaab:
  Sale − lagat − kharcha = BACHA.

SALE
  7 din / 30 din / saal. Rozana chart, dine-in vs takeaway vs
  delivery, category ka hissa, food cost %, void.
  Aur SAB SE ZIADA NAFA dene wali cheezein — jo sab se ziada bikta
  hai zaroori nahi wohi sab se ziada deta ho. Lagat recipe se (ya
  seedhe jure hue inventory item se) nikalti hai; jis item ka koi
  rasta na ho us ka nafa nahi dikhaya jata — ghalat number se koi
  number behtar nahi.

STOCK — LIVE
  Godaam ki kul maliyat, aur har cheez ka abhi ka stock. Har naam ke
  neeche ek baareek lakeer: reorder se kitna ooper ya neeche (sabz
  theek, ambar khatam honay ko). Jo neeche gir chuki hain wo khud
  ooper aa jati hain. Naam se dhoondiye.
  Kisi cheez par tap: subah thi / aaj aaya / aaj laga / abhi hai.

  INVENTORY KA TRACK AB YAHAN SE. Sitara dabate hi wo cheez "Nazar
  mein" mein aa jati hai AUR raat ki closing parchi par bhi (wahi
  `is_tracked` nishan). Pehle yeh Inventory screen mein chhupa hua
  tha — aap ke demo mein 10 mein se 0 item tracked thay: feature
  maujood tha, koi on hi nahi karta tha.

ACCOUNT
  Abhi daraz mein kitna, kis ka account khula hai aur KITNE DIN SE,
  pichhli closings ka farq (cash kam/zyada), aur aaj ka kharcha.


------------------------------------------------------------
 TESTING (chala kar, guess nahi)
------------------------------------------------------------
  Bina login /m                 -> login par, usi business ka     PASS
  Login ke baad                 -> seedha portal, dashboard nahi  PASS
  Super admin ne nahi diya      -> page 403, API 403              PASS
  Cashier ka login (feature ON) -> page 403, API 403, watch 403   PASS
  Super admin dialog            -> link bana, copy chala          PASS
  Chalu karne par baqi 42 modules band nahi hue                   PASS
  Sitara -> inventory_items.is_tracked 0 -> 1 -> 0                PASS
  Stock dhoondna ("chick" -> Boneless Chicken)                    PASS
  Item ki tafseel: subah 2.4 KG, abhi 2.4 KG, maliyat 4,535       PASS
  Arsa badalna 30 din -> 7 din (sirf Sale ka hissa aaya)          PASS
  Unit ek hi paimane par (42.002 KG ke sath "aaya 0.005 KG")      PASS
  Nafa ka hisaab: 2 x 42.57 = 85 lagat, 90 rate -> 5%             PASS

  run_migrations --stage=all -> ok=42 skipped=0 failed=0
  Restaurant 43 screens -> 0 masla, 0 JS error
  Retail      41 screens -> 0 masla, 0 JS error


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    APPROVED_UI_SHA256.json
    approved_ui\super_admin.html
    public\api.php
    public\router.php
    public\login-submit.php
    src\Auth.php

    YEH NAYI HAIN — delete karne ki zaroorat nahi:
    approved_ui\owner_mobile.html
    src\Services\OwnerMobile.php

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V160.zip extract karein, phir Railway par deploy.
    (Koi nayi column nahi — migration ka naya kaam nahi.)

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        MENU_SORT_OK
        DISCOUNTS_OK
        MODULE_GROUPS_OK
        COLLATION_OK
        RETAIL_WIRING_OK

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------
      VERSION                         V160
      src\Auth.php                    function ownerMobile
      src\Services\OwnerMobile.php    class OwnerMobile
      public\api.php                  case 'owner-mobile-boot'
      public\api.php                  sa-owner-mobile-toggle
      public\router.php               OWNER PORTAL KA APNA PEHRA
      public\router.php               ?b=v23
      public\login-submit.php         after_login
      approved_ui\super_admin.html    function bizOwnerApp
      approved_ui\owner_mobile.html   OWNER / EXECUTIVE — MOBILE

STEP A5 — PEHLI PROPERTY KO DENA
    Super Admin -> All Businesses -> "Owner app" -> Turn on -> Copy
    Link malik ko bhej dein. Wo kholay, login karay, portal khul jaye.


============================================================
 PART B — OFFLINE PACKAGE (customer ka PC)
============================================================

Owner app CLOUD ki cheez hai. Malik ka phone dukan ke computer se
nahi, internet se juRta hai — is liye yeh Railway par chalta hai,
offline node par nahi. Offline package ko is update se koi farq
nahi parta; phir bhi aap chahen to UPDATE.bat chala lein (koi
migration nahi, is liye seedha hai).

Yani: malik ghar se, safar se, kahin se bhi dekh sakta hai — dukan
ka computer band ho tab bhi, kyunke aankre cloud par pohanch chuke
hote hain.


------------------------------------------------------------
 BROWSER MEIN CHECK
------------------------------------------------------------
 1. Super Admin -> "Owner app" -> Turn on -> link copy
 2. Phone ke browser mein wo link kholein -> login aaye
 3. Malik ke user se login -> seedha owner portal khule
 4. Stock -> kisi cheez par sitara -> "Nazar mein" mein aa jaye
    -> us raat ki closing parchi par bhi nazar aaye
 5. Cashier ke user se wohi link -> "Only the owner or a manager"
 6. Super Admin se Turn off -> link kholne par "not switched on"

Madad: Wabwar Software House — +92 342 5095104

// build: V160 build 2026-10-20

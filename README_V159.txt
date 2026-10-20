============================================================
 V159 — TASWEEREN LOCAL · KHULA ACCOUNT POORA · 3" REPORTS
 Wabwar Software House                     build 2026-10-20
============================================================

Aap ke teen points. Baqi kisi cheez ko haath nahi lagaya: KOT, payment,
bill numbering, FBR, discount, menu ki tarteeb — sab V158 jaise hain.


------------------------------------------------------------
 1) ITEM KI TASWEER AB CLOUD PAR NAHI JATI
------------------------------------------------------------
Aap ka andesha bilkul theek tha. Tasweer `menu_items.image_url` mein
BASE64 ban kar baithti hai (har item tak ~1MB), aur `menu_items` sync
ki DONO fehriston mein tha:

    push_tables: ... 'menu_items', ...     <- offline se cloud JATI THI
    pull_tables: ... 'menu_items', ...     <- cloud se offline AATI THI

Yani counter par lagayi hui har tasweer seedha live database mein
chali jati thi — jahan usay koi dekhta hi nahi tha.

AB:
  * Offline par lagayi hui tasweer USI COMPUTER PAR rehti hai.
    Push par wo payload se nikal di jati hai.
  * Online par lagayi hui tasweer OFFLINE PAR AA JATI HAI — jaisa aap
    ne kaha. (Aur wohi tasweeren QR table-menu aur customer ke mobile
    menu par bhi nazar aati hain.)

Sirf push rokna kaafi NAHI tha, aur yehi is kaam ka asal hissa hai:
`menu_items` pull mein bhi hai, is liye cloud ki KHALI tasweer neeche
aa kar offline wali ko mita deti. Ab qanoon ek hi hai dono taraf —
KHALI tasweer kabhi likhi hi nahi jati. Tasweer sirf tab badalti hai
jab doosri taraf se ASAL tasweer aaye. Baqi sab columns (naam, rate,
category) pehle ki tarah dono taraf chalte rehte hain.


PEHLE SE JAMA HUI TASWEEREN — SAAF KARNE KA TARIQA

Nayi script: `scripts/clear_cloud_images.php`  (sirf Railway par)

Yeh MIGRATION NAHI hai — jaan boojh kar. Har deploy par apne aap
tasweeren mitana, chahe wo aap ne khud online lagayi hon, qabool nahi.
Aap jab chahein, haath se chalayein.

EHTIYAT: cloud ki har tasweer fazool nahi. `is_online = 1` waale items
ki tasweeren QR menu aur customer ke mobile menu par NAZAR AATI HAIN —
unhen mitana customer ko khali menu dikha dega. Is liye script ka
default scope wo tasweeren chhorta hai.

  Railway ki shell mein:

    php scripts/clear_cloud_images.php
        SIRF RIPORT. Kuch nahi mitata. PEHLE YEH CHALAYEN — yeh aap ko
        MB mein bata dega ke kitna wazan hai aur us mein se kitna
        online menu par nazar aata hai.

    php scripts/clear_cloud_images.php --apply
        Sirf wo tasweeren mitata hai jo online menu par nazar NAHI
        aatin (yani wohi jo counter se chadhi theen).

    php scripts/clear_cloud_images.php --apply --scope=all
        Saari tasweeren. QR / customer menu bhi khali ho jayega —
        sirf tab chalayein jab aap yehi chahte hon.

Script watermark ko haath nahi lagati, is liye saaf karne se har node
par be-wajah sync nahi chalta. Local par ghalti se chal jaye to khud
mana kar deti hai.


------------------------------------------------------------
 2) 2 DIN KHULA ACCOUNT — POORI SALE NAZAR AAYE
------------------------------------------------------------
Chala kar dekha. Shift 2 din pehle kholi, teen bills: 1,500 (parson)
+ 2,500 (kal) + 1,000 (aaj):

    Jagah                        Dikha      Hona chahiye
    ---------------------------  ---------  ------------
    POS ka hisaab                1,000      5,000
    Duplicate Bill ki fehrist    1 bill     3 bills
    Close account ka preview     5,000      theek tha
    Closing report (print)       5,000      theek tha

CLOSING REPORT PEHLE SE SAHI THI — wo `shift_id` se chalti hai. Ghalat
wo tha jo KHULA account dikhata tha:

    WHERE site_id=? AND business_date=? AND order_status='CLOSED'

`business_date` sirf calendar ki tareekh hai. Raat 11:59 par 48,000,
raat 12:00 par 0 — jabke account khula hai aur cash daraz mein maujood.
Cashier ko andaza hi nahi hota ke uske zimme kitni raqam hai.

AB: shift khuli ho to dono jagah USI SHIFT ka hisaab — wohi number jo
closing ke waqt report par aayega. Koi shift khuli na ho to pehle jaisa
(aaj ki tareekh), kyunke tab ginne ke liye koi account hai hi nahi.

Saath hi "Duplicate Bill" ki fehrist bhi khule account ki ho gayi —
pehle raat 12 bajte hi khali ho jati thi aur cashier apni hi shift ka
bill dobara nikaal nahi sakta tha.

Dashboard jaan boojh kar waisa hi hai: wo DIN ka dashboard hai, us par
tareekh hi theek paimana hai.


------------------------------------------------------------
 3) HAR REPORT 80mm (3 INCH) PAR
------------------------------------------------------------
Reports ka safha sirf A4 par chhapta tha (`@page{margin:14mm}`), magar
counter par A4 ka printer hota hi nahi — wahan 80mm thermal laga hota
hai. Sale ya closing ki report kaghaz par nikalni ho to koi rasta nahi
tha.

AB: Reports ke upar naya button — "Print 3"". Restaurant aur Retail
dono par. Har report, sirf teen par nahi.

Yeh wohi engine istemal karta hai jo bill aur shift-closing chhapta
hai (`BillTemplate` + `Pdf::receipt`), do alag copies nahi — is liye
kaghaz par shakl bhi wohi rehti hai.

EK BAAT JAAN LENA ZAROORI HAI: 80mm par ek line mein sirf 37
characters aate hain. "Opening / closing stock" ke 7 columns saath
saath kabhi nahi samayenge; zabardasti karne par har column 4-5
characters ka reh jata aur number KAT jate — jo report na hone se bhi
bura hai. Is liye har item ka apna chhota block banta hai:

    Burger Bun
      Unit                          pcs
      Opening                        86
      Purchased                      20
      Sold                            0
      Closing                       106
      Current stock                 106

Jahan sirf do column hain (Sales summary waghera) wahan yeh khud ba
khud ek hi line par aa jata hai, is liye chhoti reports phoolti nahi.


DUPLICATE BILL — YAHAN ASAL MASLA MILA

Reprint ka button asal bill chhapta HI NAHI tha. Bill dobara banaya
jata tha us data se jo screen ke paas tha — aur us ke paas sirf kul
raqam thi:

    slipPreview('BILL',[{name:'Bill total',qty:1,price:x.amount}],
      { t:{sub:x.amount, disc:0, svc:0, tax:0, grand:x.amount} });

Yani customer ko jo "duplicate" milta tha us par EK HI line hoti thi —
"Bill total". Na items ke naam, na quantity, aur discount / service /
tax teenon SIFAR likhe aate, chahe asal bill par lage hon. Hisaab
maangne wale customer ke liye wo kaghaz kisi kaam ka nahi tha, aur tax
waale bill par to ghalat bhi.

`bill-pdf` naam ka raasta pehle se maujood tha jo bill number se ASAL
items aur ASLI tax server se nikal kar wohi 80mm shakl banata hai jo
pehli dafa chhapi thi. Duplicate ab wohi hai.


------------------------------------------------------------
 EK CHEEZ JO V158 KE ZIP MEIN REH GAYI THI
------------------------------------------------------------
"Opening / closing stock" report ka unit wala sudhaar code mein mojood
tha magar V158 ke zip mein nahi gaya tha. Ab is package mein hai:

  Stock andar GRAM aur MILLILITRE mein rakha jata hai, is liye 42 kilo
  chawal report par "42,000" chhapta tha — aur wo bilkul rupees jaisa
  lagta hai. Ab 1000 se ooper gram KG aur ml L ban jate hain (wohi
  qanoon jo Inventory screen chalati hai), aur note mein saaf likha
  hai ke yeh QUANTITY hai, raqam nahi.

  Taqseem paanchon columns par EK SATH hoti hai, warna jis item ka
  stock aaj khatam ho chuka ho us ka Opening 50,000 (gram) aur Current
  0 (KG) ek hi line mein aa jata.

Isi tarah `APPROVED_UI_SHA256.json` mein V158 ke zip ka
`inventory_creation.html` ka hash purana reh gaya tha (aakhri padding
wali tabdeeli ke baad register dobara nahi bana tha). Ab theek hai.
Chalne mein is se koi farq nahi parta, yeh sirf record hai.


------------------------------------------------------------
 TESTING (chala kar, guess nahi)
------------------------------------------------------------
  #1 role=local -> push payload mein image_url = NULL             PASS
     (naam, rate waghera saath gaye)                              PASS
     role=cloud -> pull payload mein tasweer MAUJOOD              PASS
     khali tasweer wali row aayi -> local tasweer bachi rahi      PASS
     magar us row ke baqi column update hue                       PASS
     asal tasweer wali row aayi -> tasweer lag gayi               PASS
     clear_cloud_images (riport) -> 4 items / 0.57 MB, online
       aur offline alag alag ginay                                PASS
     --apply -> sirf offline waali 2 gayin, online 2 bachin       PASS
     --apply --scope=all -> baqi 2 bhi gayin                      PASS
     local par chalayi -> IMAGES_SKIP                             PASS
     watermark (updated_at) nahi chhera                           PASS

  #2 2 din purani khuli shift, 3 bills (1500+2500+1000):
     khule account ke sath   -> 5,000 / 3 bills                   PASS
     account band karne par  -> 1,000 / 1 bill (pehle jaisa)      PASS
     Duplicate Bill ki fehrist -> chaaron bills                   PASS

  #3 report-pdf: stock_ledger, sales_summary, stock_on_hand,
     low_stock -> 200 application/pdf                             PASS
     Retail: sales_summary, sales_by_item, sales_by_dept          PASS
     80mm par chhapi hui report parhne ke qabil (dekh kar)        PASS
     duplicate bill -> 4 items, discount -180, tax 259            PASS

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
    approved_ui\restaurant_pos.html
    approved_ui\reports.html
    approved_ui\retail\reports.html
    public\api.php
    public\router.php
    src\Services\BillTemplate.php
    src\Services\PageData.php
    src\Services\ReportService.php
    src\Services\Sync.php

    YEH NAYI HAI — delete karne ki zaroorat nahi:
    scripts\clear_cloud_images.php

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V159.zip extract karein, phir Railway par deploy.
    (Is version mein koi nayi column nahi — migration ka koi naya
    kaam nahi.)

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        MENU_SORT_OK
        DISCOUNTS_OK
        RETAIL_OPS_OK
        MODULE_GROUPS_OK
        COLLATION_OK
        RETAIL_WIRING_OK

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                             SEARCH FOR
      -------------------------------  ------------------------
      VERSION                          V159
      src\Services\Sync.php            TASWEER UPAR NAHI JATI
      src\Services\Sync.php            KHALI TASWEER KISI MOJOOD
      src\Services\PageData.php        TAREEKH SE NAHI, SHIFT SE
      src\Services\BillTemplate.php    public static function report
      src\Services\ReportService.php   BARI UNIT MEIN DIKHAO
      public\api.php                   case 'report-pdf'
      public\router.php                ?b=v22
      approved_ui\reports.html         print80Btn
      approved_ui\retail\reports.html  btnPrint80
      approved_ui\restaurant_pos.html  DUPLICATE AB ASLI BILL HAI
      scripts\clear_cloud_images.php   IMAGES_REPORT

STEP A5 — PURANI TASWEEREN (jab aap chahein)
    Railway ki shell mein pehle sirf riport:
        php scripts/clear_cloud_images.php
    Number dekh kar phir faisla karein (upar point 1 dekhein).


============================================================
 PART B — OFFLINE PACKAGE (customer ka PC)
============================================================

Pehle Railway par deploy, phir customer ke PC par shop band hone ke
baad UPDATE.bat. (Offline package aap ke apne server se banta hai, aur
yeh saari files `runtime\app.sealed` ke andar hoti hain — haath se
replace nahi hotin.)

Is version mein koi migration nahi, is liye update seedha hai.

AHEM — PEHLI SYNC KE BAAD: is update se pehle jo tasweeren cloud par
ja chuki hain, wo wahan maujood rehti hain jab tak aap script na
chalayein. Offline par koi tasweer gayab NAHI hogi — naya qanoon khali
tasweer ko likhne hi nahi deta.


------------------------------------------------------------
 BROWSER MEIN CHECK
------------------------------------------------------------
 1. Offline POS par kisi item ki tasweer badlein -> sync hone dein
    -> online par wo item khulein: tasweer wahan NAHI gayi
    -> offline par abhi bhi maujood
 2. Online par kisi item ki tasweer lagayen -> sync
    -> offline par wo tasweer aa jaye
 3. Account kholen, bill banayen, raat guzarne dein (ya shift ki
    tareekh peeche kar ke dekhein) -> POS par poori sale nazar aaye,
    aur Duplicate Bill mein dono din ke bills
 4. Reports -> koi bhi report chalayen -> "Print 3"" -> 80mm PDF
 5. POS -> Duplicate Bill -> kisi bill par Reprint
    -> poore items, asal discount aur asal tax ke sath

Madad: Wabwar Software House — +92 342 5095104

// build: V159 build 2026-10-20

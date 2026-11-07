============================================================
 V185 — HAR REPORT PAR WOHI SALE, AUR UPAR WALA NEELA
        KHANA HAR JAGAH SE GAYA
 Wabwar Software House                     build 2026-11-07
============================================================

TEEN FILES. Koi migration nahi, database nahi badla, POS ki
screen nahi badli.

AAP NE KAHA:
"Ab all reports ko read kro, sab main amount different aa rhi
 hai... sab per amount same ani chaiye; all report set kro.
 Account wise sale ko chor do wo theek hai. Oper jo blue main
 statement likhi hai wo b har jagah se remove kr do."

Saari 34 reports ko ek hi arse par chala kar asal data se mila
kar dekha. Nateeja: DO asli kharabiyan nikleen, aur EK cheez
aisi thi jo kharabi nahi thi magar dikhti ghalat thi.


############################################################
 1) SHIFT / DAY CLOSING — ASLI BUG, AUR BARA
############################################################

Yeh report `cashier_shifts` ke MEHFOOZ purane column parhti thi
(`gross_sales`, `invoice_count`, `cash_sales` waghera). Wo
column sirf us soorat mein bharte hain jab shift closing ke
poore raaste se band ho.

Rig ke asal data par haal yeh tha:

    shift 1   mehfooz  0 bill /     0      asal 4 bill / 14,000
    shift 6   mehfooz  0 bill /     0      asal 5 bill /  5,504.80
    shift 9   mehfooz  4 bill / 1,120      asal 3 bill /    620

Yani kuch par bilkul KHALI, aur shift 9 par to SEEDHA GHALAT.
Report ka jama 1,120 aa raha tha jab ke us arse ki sale
57,641.30 thi.

DOOSRI KHARABI ISI JAGAH: chunao SHIFT ki tareekh par tha, BILL
ki tareekh par nahi. 31 October ka khula till agli raat tak
chala, to us ke 6,000 ke November wale bill 1-7 November ki
report se bahar reh jate thay. Aur jin billon par shift likha
hi nahi (tablet/QR ka order) wo kisi line mein aate hi nahi
thay — 1,400 aur. Kul 7,400 kam.

AB:
    • Sale, Bills aur Discount SIDHE billon se
    • Cash / Card / Credit `payments` se
    • Kharcha `expenses` se
    • Bill us ARSE ke, chahe till kisi bhi din khula ho
    • Bina-shift wale bill apni "(no account)" line mein

    Yani bilkul wahi ginti jo closing parchi
    (OpsService::shiftReport) aur baqi saari reports karti hain.

JO MEHFOOZ HI RAHEGA: Opening float, Counted aur Variance — yeh
daraz ki GINTI hain, billon se nahi nikalte.

    Chala kar dekha: ab 31 bill / 57,641.30 — Sales summary ke
    bilkul barabar.


############################################################
 2) SALES BY ITEM / CATEGORY — YEH BUG NAHI THA
############################################################

Aap ne pakra: "Sale summary main total 68,329.81 but sale by
item main aa rha 73,385."

`line_total` ITEM ki qeemat hai. Bill ka discount, service
charge aur delivery item ki line mein hote hi nahi. Jahan
discount bara ho wahan item wala number UPAR chala jata hai —
isi liye aap ke yahan 73,385 > 68,329.81.

(Rig par saabit kar ke dekha: har band bill par `order_items`
 ki lineon ka jama bill ke subtotal ke BILKUL barabar hai, 0
 bill par farq. Aur dono reports ek hi bill uthati hain — koi
 extra bill shamil nahi ho raha tha.)

MAGAR BAAT AAP KI THEEK HAI ke har report ka total ek jaisa
aana chahiye. Is liye ab ek aur column:

    AMOUNT  =  item ki apni qeemat        (jaisa tha waisa hi)
    SALE    =  item ko us ke bill ke discount / service /
               delivery / tax ka apna HISSA de kar

               hissa = line_total x (grand_total / subtotal)

`Sale` ka jama ab bill ke grand_total ke barabar aata hai, yani
Sales summary ke barabar. Upar ka bara number bhi yehi hai.

EK CHHOTI BAAT: `Sale` ka total lineon ko jor kar nahi, SEEDHA
BILLON se ginta hai — do wajah se: (a) har line par gol karne
se sainkron lineon par paise khisak jate hain, (b) jin billon
ki koi item line hi na ho un ki sale gum ho jati. Aise bill ki
raqam ab "(bills without item lines)" ki apni line mein saaf
likhi jati hai, taake lineon ka jama aur Total hamesha ek rahe.
Asal data par aisa bill hota hi nahi.


############################################################
 3) HAR SALE REPORT KE UPAR AB SALE KA NUMBER
############################################################

Upar ke chaar tile pehle chaar numeric column se bante hain.
Teen reports par `Total` un chaar mein aata hi nahi tha, yani
sab se ahem number screen par upar nazar hi nahi aata tha:

    Sales summary        tile: Bills | Subtotal | Discount | Service
    Order / invoice      tile: Items | Subtotal | Discount | Tax
    FBR daily/monthly    tile: Bills | FBR sent | Not sent | Taxable

AB in par bhi Total tile par hai. Chala kar dekha: 15 ki 15
sale reports ke upar ab sale ka number maujood hai.


############################################################
 4) UPAR WALA NEELA KHANA — HAR JAGAH SE GAYA
############################################################

Screen par se hatana kaafi nahi tha: wo matn report ke data ke
saath jata hai, is liye CSV aur print mein bhi chala jata. Ab
do jagah se band kiya gaya — screen par bhi, aur `shape()` mein
jar se bhi. Ek hi jagah, to aage koi nayi report likhte waqt
bhoolne ka sawal nahi.

    Chala kar dekha: 34 mein se kisi report par note nahi.

EK BAAT JO AAP KO JAN LENI CHAHIYE: V184 mein tax ki wazahat
usi neele khane mein likhi thi ("tax qeemat ke andar hai, jama
nahi hota"). Ab wo wazahat sirf column ke NAAM mein rehti hai —
"Tax (incl.)". Agar aap chahein ke yeh baat kahin aur likhi ho
to bata dein.


############################################################
 5) JIN REPORTS KA SALE SE ALAG HONA THEEK HAI
############################################################

Yeh alag cheez naapti hain; in ka sale ke barabar hona GHALAT
hota:

    Expenses · Purchases · Supplier buys · Purchase items ·
    Wastage · Current stock · Stock movement · Refunds ·
    Voids · Discounts · Void/discount · Customers ·
    Low stock · Audit log

Aur Account wise — aap ne khud kaha "usko chor do, wo theek
hai". Us ko haath nahi lagaya: V183 ka usool (sirf us arse mein
KHULE account, har account ka poora hisaab) waisa hi hai.


############################################################
 CHALA KAR DEKHA — SAB PASS
############################################################

NAYE (V185):                                              27
    - 13 sale reports par bilkul wohi number
    - shift closing: mehfooz 99,999 / 77 bill ab nazar nahi aate
    - shift closing ke bills, discount, cash — sab asal se
    - Opening float mehfooz hi raha
    - sales by item: Amount item ka, Sale bill ka
    - lineon ka jama = Total (paison tak)
    - 34 mein se kisi report par note nahi
    - account wise ko haath nahi laga
    - kahin Roman Urdu nahi

PURANE (dobara chalaye, sab pass):
    V184 (tarteeb, tax, tile, English)                    31
    tax BAHAR se lagne wala business                       6
    V183 ke test                                           4
    account band karne ke guards                       7 + 3
    V181 · V179 ke test                              32 · 43
    V180 (net ka total, Total ka tile)                     9
    POS se reports tak ka audit                           12
    saari 34 reports                              34 / 0 error
    asli screen par (1366)                          4 · 5 · 8
    70 UI file ke hash                          0 mismatch *

* reports.html ka hash JAAN BOOJH KAR badla aur
  APPROVED_UI_SHA256.json mein naya likh diya gaya.

PURANE IMTIHAAN JAAN BOOJH KAR BADLE:
    V184 ke chaar imtihaan neele khane ka matn parhte thay. Aap
    ne wo khana hatwa diya, is liye ab wo yeh janchte hain ke
    note KHALI ho. Paanchwan imtihaan "nishan sirf ek report
    par" maangta tha; ab nishan chhe reports par hai, aur us ki
    jagah ek behtar imtihaan aaya: HAR sale report ke tile par
    sale ka number maujood ho.

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data par chala kar dekhna.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
TEEN FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\ReportService.php   (shift closing, item/category,
                                      tile, note khatam)
    approved_ui\reports.html         (neela khana nahi chhapta)
    APPROVED_UI_SHA256.json          (reports.html ka naya hash)

DATABASE NAHI BADLA. KOI MIGRATION NAHI.
POS, dashboard aur account wise nahi badle.

NOTE: V183 aur V184 pehle lagane hain. Agar nahi lagaye to un
ki files bhi sath chadha dein.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Chaar files chadha dein. Koi migration nahi.

PART A — OFFLINE PC
    Railway par chadhate hi cloud ka package naya ho jata hai,
    phir us PC par:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\ReportService.php
    approved_ui\reports.html
    APPROVED_UI_SHA256.json

STEP A2 — EXTRACT
    SaaSVersion_V185.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V185
      src\Services\ReportService.php  SALE AB ASAL BILLON SE GINI
      src\Services\ReportService.php  ITEM KI APNI QEEMAT
      src\Services\ReportService.php  UPAR WALA NEELA KHANA HAR JAGAH
      approved_ui\reports.html        UPAR WALA NEELA KHANA HAT GAYA
      APPROVED_UI_SHA256.json         cee40f42e75cd1eb

STEP A4 — CHALA KAR DEKHEIN
    1. 7/11/2026 se 7/11/2026 chun kar yeh reports kholein:
       Sales summary · Sales by item · Sales by category ·
       Sales by hour · Payment methods · Dine-in/takeaway ·
       Tax summary · Staff · Table · Order/invoice detail ·
       Waiter · Shift/day closing · FBR reconcile · FBR summary
       -> har ek ke UPAR wala bara number ek jaisa hona chahiye
    2. Shift / day closing
       -> Sale ab asal billon se (pehle khali ya ghalat aata tha)
    3. Kisi bhi report ke upar neela khana nahi hona chahiye

Madad: Wabwar Software House — +92 342 5095104

// build: V185 build 2026-11-07

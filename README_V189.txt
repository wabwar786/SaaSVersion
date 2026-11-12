============================================================
 V189 — BILL AB BAND ACCOUNT PAR NAHI LIKHA JAYEGA
        + parchi ka font chhota, khali jagah khatam
        + Duplicate Bill mein tareekh ka option
 Wabwar Software House                     build 2026-11-12
============================================================

>>> YEH ZIP V186, V187 AUR V188 KI JAGAH LAGAYEIN <<<

Un sab ka kaam is mein shamil hai:
    V186 — closing parchi ka bara font, naya saaf font PDF ke andar
    V187 — reprint par DUPLICATE, invoice detail ki tarteeb
    V188 — lambi parchi kai safhon mein

DAS FILES. Koi migration nahi, database nahi badla.


############################################################
 1) BILL GHAYAB — ASLI WAJAH MIL GAYI
############################################################

AAP NE KAHA: "Bill ghayab hota hai. Bill sirf usi account ke
show hone chahiye jo abhi running mein hai. Is ka dobara audit
karo, shayad koi aur issue nikal aaye."

Nikal aaya. Asli POS par chala kar yeh dekha:

    POS khula raha, account 600 band hua, 601 khul gaya
    POS ab bhi 600 hi samajhta raha
    cashier ka agla bill (0004) -> BAND account 600 par likha gaya
    POS reload -> account 601 -> hold 0, duplicate 0
    DB mein 3 ruke huay bill maujood, POS mein kahin nahi

AAP KA USOOL BILKUL THEEK HAI — bill sirf chalte account ke
dikhne chahiye. Masla yeh tha ke bill GHALAT account par likha
ja raha tha: ek BAND account par. Phir Hold Bills aur Duplicate
(dono chalte account ko dekhte hain) use dikhate hi nahi thay,
aur us ki sale band account ke khaate mein chali jati thi.

KYUN HOTA HAI: POS ka safha ghanton khula rehta hai. Us darmiyan
account band ho sakta hai — cashier khud band kare, ya V183 ke
baad ADMIN doosri screen se "Close this till" dabaye. POS ko
pata hi nahi chalta tha.

AB DO PEHRE:

(a) SERVER: jo `shift_id` aaye wo pehle parkha jata hai. Khula
    ho to wohi; band ya ghayab ho to CHALTA account istemal hota
    hai. Yani band account par bill likhna ab mumkin hi nahi.

(b) POS: server har hold ke jawab mein ASLI shift bhejta hai.
    POS us se apna account durust kar leta hai, hold ki fehrist
    taza karta hai, aur cashier ko saaf batata hai:
    "Account badal gaya tha (pehle 700, ab 701) — naya bill isi
     par ja raha hai"

CHALA KAR DEKHA (wohi scenario, V189 par):
    POS ne BAND account ka id bheja  ->  bill KHULE account par
    POS ka apna account 700 -> 701, khud theek ho gaya
    hold fehrist foran taza

EK AUR BAAT JO AUDIT MEIN NIKLI:
"Preview / Print" kuch bhi MEHFOOZ NAHI karta. Kagaz par jo
chhap kar customer ke paas jata hai, wo us waqt DB mein hota hi
nahi. Bill tab mehfooz hota hai jab "+ New Bill" dabayein (khud
hold ho jata hai) ya payment complete karein. Agar us darmiyan
software band ho jaye, to wo bill kahin nahi hota.

Yeh V189 mein nahi badla — batana zaroori tha. Agar kahein to
print dabate hi bill khud hold ho jaya kare, main wo daal doon.


############################################################
 2) SERVICE CHARGE / DISCOUNT / DELIVERY GUM
############################################################

Yeh rig par (V188/V189) DOBARA NAHI HO SAKA. Aap ki batai hui
poori tarteeb do tarah se chala kar dekhi — seedha print wala
raasta, aur asli restaurant wala raasta jahan pehle KOT jata
hai. Dono dafa sab theek raha:

    New Bill ke baad DB : svc 300 · FISH FRY/GRILL + MINERAL
                          WATER · total 2120
    hold se wapas khola: dono item wapas, service charge wapas

Magar point 1 ka masla is ki wajah ban sakta hai: agar bill BAND
account par chala gaya to cashier use fehrist mein dhoond nahi
pata, dobara banata hai, aur charges reh jate hain. Us surat
mein V189 se yeh bhi theek ho jana chahiye.

AGAR PHIR BHI HO, to mujhe yeh batayein:
    - POS screen ke neeche daayen kone ka build number
      (jaise "POS V189 build 2026-11-12")
    - Us waqt kitne account khule thay
Us se main theek jagah par haath daal sakoon ga.


############################################################
 3) CLOSING PARCHI — FONT CHHOTA, KHALI JAGAH KHATAM
############################################################

AAP NE KAHA: "Font size ziada large ho gaya tha, thora chota
karna hai. Aur page end hone par bohat ziada space blank
chhorta hai."

(a) FONT 11 -> 10. Ek line mein ab 36 harf (11 par 33 thay), to
    item ke naam bhi kam katenge. Line ka faasla 15 -> 14.

(b) KHALI JAGAH: V188 mein bhara hua safha POORA 780pt ka likha
    jata tha, chahe us ka matn 700pt par khatam ho jaye. Roll
    par wo bacha hua hissa khali kagaz ban kar nikalta tha — har
    safhe ke aakhir mein.

    Ab har safhe ki lambai us ke APNE matn se banti hai. Hadd
    wahi (koi safha 780pt se lamba nahi), bas khali jagah nahi
    bachti.

    Chala kar dekha: safhon ki kul lambai = matn ki kul oonchai
    + har safhe ka hashiya. Ek point bhi faltu nahi.


############################################################
 4) DUPLICATE BILL MEIN TAREEKH KA OPTION
############################################################

Fehrist sirf chalte account ki hoti thi, aur wo bhi safha load
hote waqt ek dafa. Kal ka bill dobara chhapna ho to Reports
kholni parti thi.

AB: upar tareekh ka khana, bil-default AAJ. Tareekh badalte hi
us din ke band bill aa jate hain. Aaj par wapas aayein to wahi
fehrist jo safhe ke saath aayi thi (ek request bach jati hai).

PEHRA WAHI JO BAQI HAR JAGAH HAI: cashier sirf apne bill dekhta
hai, manager sab; aur site ki shart lagi hui hai, to kisi doosre
business ka bill kisi soorat nahi aa sakta.


############################################################
 5) "SYSTEM HANG HO JATA HAI"
############################################################

Is par main abhi kuch nahi keh sakta — andaza lagana theek nahi
hoga. Agle baar hone par yeh bata dein:

    - Kis screen par hang hua (POS, reports, koi aur)?
    - Us waqt kya kar rahe thay (bill band, print, report)?
    - Screen bilkul ruk jati hai ya chalti hai magar jawab nahi
      deti?
    - Kitni der mein khud theek ho jata hai, ya software band
      karna parta hai?

Ek shak zaroor hai jise main pehle bhi nishan zad kar chuka
hoon: `sync_loop.php` ka ek se zyada nuskha chal parna aur DB ke
connections bhar jana ("1040 Too many connections"). Us ka hal
banaya hua hai magar aap ne abhi Final nahi kaha. Kahein to
agle package mein daal doon.


############################################################
 CHALA KAR DEKHA — SAB PASS
############################################################

NAYE (V189):                                              14
    - khula account diya jaye to wohi
    - BAND account diya jaye to chalta account milta hai
    - bakwas id par bhi chalta account
    - font 10 par 36 harf; customer ka bill 37 par
    - safhon ki kul lambai = matn + hashiye (khali jagah nahi)
    - customer ka bill ek hi safha

ASLI POS PAR (browser):                                    7
    - duplicate mein tareekh ka khana, bil-default aaj
    - kal ki tareekh par kal ka bill
    - search, aur aaj par wapasi

PURANE (dobara chalaye, sab pass):
    V188 · V187 · V186                          18 · 14 · 20
    V185 ke test                                          27
    V184 · tax wala business                          32 · 6
    V183 · account band karne ke guards            4 · 7 + 3
    V181 · V179 ke test                             32 · 43
    POS se reports tak ka audit                           12
    saari 34 reports                           34 / 0 error
    70 UI file ke hash                            0 mismatch

JO MAIN NAHI KAR SAKA:
    Aap ke apne customer ke data aur printer par chala kar
    dekhna.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
DAS FILES (README aur screenshot ke ilawa):

    VERSION
    src\Services\PosService.php      (band account par bill nahi)
    src\Services\PageData.php        (billsOn — tareekh wale bill)
    src\Services\ReceiptFont.php     (V186 — font ke bytes)
    src\Services\Pdf.php             (V188 safhe + V189 khali jagah)
    src\Services\BillTemplate.php    (V186 size, V187 DUPLICATE)
    src\Services\ReportService.php   (V187 — invoice detail tarteeb)
    public\api.php                   (pos-bills-on, parchi ka size)
    public\ops_db.js                 (V187 — reprint par dup=1)
    public\router.php                (ops_db.js ka ?b= number)
    approved_ui\restaurant_pos.html  (syncShift + tareekh ka khana)
    APPROVED_UI_SHA256.json          (us ka naya hash)

DATABASE NAHI BADLA. KOI MIGRATION NAHI.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    Sab files chadha dein. Koi migration nahi.

PART A — OFFLINE PC
    Railway par chadhate hi cloud ka package naya ho jata hai,
    phir us PC par:
        GET_UPDATE.bat   phir   INSTALL_UPDATE.bat

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\PosService.php
    src\Services\PageData.php
    src\Services\Pdf.php
    src\Services\BillTemplate.php
    src\Services\ReportService.php
    public\api.php
    public\ops_db.js
    public\router.php
    approved_ui\restaurant_pos.html
    APPROVED_UI_SHA256.json
    (ReceiptFont.php nayi hai — delete karne ki zarurat nahi)

STEP A2 — EXTRACT
    SaaSVersion_V189.zip extract karein

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  ------------------------------
      VERSION                         V189
      src\Services\PosService.php     BAND HO CHUKE ACCOUNT PAR BILL
      src\Services\PageData.php       kisi bhi ek din ke BAND bill
      src\Services\Pdf.php            HAR SAFHA APNE MATN JITNA LAMBA
      public\api.php                  DUPLICATE BILL MEIN TAREEKH KA
      approved_ui\restaurant_pos.html ACCOUNT KAHIN AUR SE BAND HO
      approved_ui\restaurant_pos.html dupDate
      APPROVED_UI_SHA256.json         7b5a11cfa6433a59

STEP A4 — CHALA KAR DEKHEIN
    1. POS kholein. Doosri screen se (ya doosre PC se) wohi
       account band kar dein, naya khol dein.
       Ab POS par ek bill banayein aur "+ New Bill" dabayein.
       -> paigham aana chahiye: "Account badal gaya tha..."
       -> bill NAYE account par hona chahiye, aur Hold Bills
          mein nazar aana chahiye
    2. Duplicate Bill kholein
       -> upar tareekh ka khana, aaj ki tareekh
       -> kal ki tareekh chunein -> kal ke bill
    3. Koi shift band kar ke parchi dekh lein
       -> matn thora chhota
       -> safhe ke aakhir mein khali kagaz nahi

Madad: Wabwar Software House — +92 342 5095104

// build: V189 build 2026-11-12

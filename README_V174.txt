============================================================
 V174 — BAND BILL KA HOLD MEIN WAPAS AANA, AUR BUSINESS KI HADD
 Wabwar Software House                     build 2026-10-28
============================================================

V174 MEIN V173 AUR US SE PEHLE KA SAB KUCH SHAMIL HAI.

Teen cheezein is mein hain:
  1. band bill ka Hold mein wapas aana — ASAL SABAB mil gaya
  2. ek business ka data doosre business se na badle — surakh band
  3. sale report mein bhi sirf poore huay bill (V173 wala usool)


############################################################
 1) BAND BILL WAPAS HOLD MEIN — ASAL SABAB
############################################################

Pehle maine kaha tha ke yeh sirf bill number dobara istemal hone ki
wajah se nazar aata hai. WO POORI BAAT NAHI THI. Ab asal sabab mil
gaya hai, aur wo sync mein tha.

PEHLE YEH JAANCHA:
Poore software mein `order_status='OPEN'` SIRF naya order banate
waqt likha jata hai. Koi bhi code band bill ko dobara nahi kholta.
Yani application ki taraf se yeh mumkin hi nahi.

MAGAR SYNC ROW KO POORA BADAL DETA HAI.
Cloud par node ki bheji hui row par koi shart nahi lagti thi — jo
row node bhejta, cloud bilkul waise hi likh deta, `order_status`
samet. Nateeja:

    - bill cloud par band hua (ya pehle sync ho kar band hua aur
      node ki apni copy khuli reh gayi)
    - node ne agle sync par apni purani KHULI copy bheji
    - cloud ne CLOSED ko OPEN kar diya, hold_status 1
    - aur bill dobara Hold Bills mein aa gaya

CHALA KAR DEKHA:
    pehle : bill CLOSED, hold_status 0
            node ne wahi order OPEN bheja (us ki row 10 minute PURANI)
            -> CLOSED se OPEN, hold_status 0 se 1          <-- masla
    ab    : cloud ne wo row chhorh di, bill CLOSED hi raha  OK

QANOON AB YEH HAI: bill ka safar sirf AAGE chalta hai.
OPEN -> CLOSED / CANCELLED / VOID. Jo bill yahan khatam ho chuka,
usay koi bahar se aane wali row dobara nahi khol sakti. Aisi row
chhorh di jati hai aur sync ke log mein darj ho jati hai.

Poori row chhorhi jati hai, sirf status nahi — kyunke agar node ki
copy abhi khuli hai to us par naye items bhi lag chuke ho sakte
hain, aur wo band bill par likhna wohi purana "band bill dobara
likh diya" wala nuqsaan hai (V168).

SYNC KA BAQI KAAM WAISA HI CHALTA HAI — 5 test:
    naya khula bill node se cloud par aaya                   OK
    khule bill par node ki tabdeeli lagi                     OK
    node ne bill BAND kiya -> cloud par bhi band hua         OK
    phir node ne purani khuli copy bheji -> band hi raha     OK
    cancel kiya hua bill bhi dobara nahi khula               OK

JO BAAT PEHLE BATAI THI WO BHI DURUST HAI: bill number har din
dobara shuru hote hain, is liye kal ka paid #0008 aur aaj ka hold
#0008 do alag bill hote hain. Wo alag maamla hai aur wo abhi
waisa hi hai.


############################################################
 2) EK BUSINESS KA DATA DOOSRE SE NA BADLE
############################################################

Aap ki hidayat par poora audit kiya. Ek asli surakh mila — sync
mein.

SURAKH KYA THA:
Cloud par aane wali row SIRF `id` se dhoondi jati thi:

    SELECT 1 FROM <table> WHERE id=?
    UPDATE  <table> SET ... WHERE id=?

tenant ki koi shart nahi. Aur saath hi upar `tenant_id` zabardasti
bhejne wale node ka laga diya jata tha. Dono mil kar yeh bante thay:

    agar kisi DOOSRE business ki row ka id wohi nikla, to us ki row
    badal bhi jati AUR us ka tenant_id bhi is business ka ho jata —
    yani doosre ka bill, menu ya customer is business mein chala
    aata.

KYA YEH HO SAKTA THA:
UUID random hote hain, is liye ittefaqan nahi. Maine jaancha — aap
ke database mein abhi koi aisa takrao nahi hai, aur seed mein koi
fixed id bhi nahi.

MAGAR EK BOHAT AAM SHORTCUT SE ZAROOR HOTA: kisi naye customer ke
liye chalta hua offline folder COPY kar dena. Us soorat mein dono
ke saare id bilkul ek jaise hote, aur cloud par dono businesses ek
doosre ka data mitate rehte — bilkul khamoshi se.

AB: row apne hi business ke andar dhoondi jati hai, aur UPDATE bhi
usi shart ke saath chalti hai. Kisi doosre business ki row mile to
HAATH NAHI LAGATE — conflict mein darj kar ke chhorh dete hain.

CHALA KAR DEKHA:
    B ka customer banaya, phir A ke node se WAHI id push ki
      pehle : A ki row ne B ka customer badal diya hota
      ab    : B ka customer waisa ka waisa, A ki row rad      OK
    apne hi business ki row normally badalti hai              OK

BAQI SOFTWARE BHI JAANCHA:
    Har wo jagah dhoondi jahan user ki di hui id se seedha likha
    jata hai (16 jagahen). Sab mein pehle `site_id`/`tenant_id` se
    jaanch hoti hai, phir id se likha jata hai — yani tareeqa theek
    hai. Password reset aur mobile app ke inventory wale raaste
    alag se parh kar dekhe, dono mehfooz.

    Aur padhne wale raaste chala kar dekhe — Hold Bills, POS ka
    menu, Running Orders, sale report, shift list: kisi mein bhi
    doosre business ka ek bhi row nahi aaya.                   OK


############################################################
 3) SALE REPORT MEIN BHI SIRF POORE HUAY BILL
############################################################

V173 mein shift ki closing parchi se cancel aur hold wale bill
nikaal diye thay. Magar REPORT ki lakeer reh gayi thi — aur wohi 25
reports chalati hai, un mein Sale Summary bhi jo aap roz dekhte
hain. Wahan purani shart `order_status <> 'VOID'` lagi hui thi.

Isi database par, pichle 30 din:

    report kehti thi    213,416
    asal sale           172,731
    ----------------------------
    jhooti sale          40,685

    kyunke:  OPEN (rokay hue) bill   31 bill   40,685

    *** V175 KI TASHEEH ***
    Yahan pehle likha tha: "CANCELLED 71 bill 93,741". WO GHALAT THA,
    aur ghalti meri thi. Wo CANCELLED rows SOFTWARE ne nahi likhi
    thin — meri apni test cleanup ki SQL ne likhi thin. Software kabhi
    `CANCELLED` nahi likhta; hold cancel karne par bhi `VOID` likha
    jata hai. Is liye aap ki asli report mein us 93,741 ka koi wujood
    nahi tha.

    Fix phir bhi durust hai aur usi tarah zaroori: report ab sirf
    CLOSED bill ginti hai, aur rokay hue (OPEN) bill us se bahar hain —
    yehi 40,685 wali asal kharabi thi.

AB sirf poore huay (CLOSED) bill ginay jate hain.

CANCEL WALE BILL CHHUPAYE NAHI GAYE: un ki apni report hai —
"Void / cancelled bills". Us report ka naam to yehi tha magar wo
sirf VOID dikhati thi, CANCEL kiye hue bill kahin nazar hi nahi
aate thay. Ab wo bhi us mein aate hain.


------------------------------------------------------------
 KYA BADLA
------------------------------------------------------------
TEEN FILES (README ke ilawa):

    VERSION
    src\Services\Sync.php
    src\Services\ReportService.php

KOI MIGRATION NAHI. Database mein koi tabdeeli nahi. POS ki screen
bhi nahi badli.


------------------------------------------------------------
 TESTING — 151 TEST, SAB PASS
------------------------------------------------------------
    sync: band bill dobara na khule        1
    sync ka baqi kaam                      5
    do business, ek hi id                  2
    padhne ke raaste (isolation)           5
    shift ka hisaab (V173)                 5 + 2 + 1
    delivery charge                       19
    billing audit (V168)                  21
    paison ka hisaab                      10
    kinare ke halaat                       8
    do din ka bill                         8
    hold / resume                          7
    note wali lines                        4
    KOT + bill band                        5
    duplicate naam                         3
    stock                                  4 + 1
    hold wapsi                             3
    closing screen                         5
    bulk delete (V166)                    28

JO MAIN NAHI KAR SAKA:
    Do asli computers (node + cloud) ke darmiyan asal sync chala kar
    dekhna. Maine wahi code chalaya jo cloud push par chalta hai
    (`Sync::applyRows`), aur wahi soorat bana kar dekhi — magar yeh
    laboratory ka test hai, do machine ka nahi.


============================================================
 LAGANE KA TAREEQA
============================================================

PART B — SERVER (Railway)
    PEHLE YAHI. Dono fix cloud ke hi hain — sync bhi, report bhi.

PART A — OFFLINE PC

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    src\Services\Sync.php
    src\Services\ReportService.php

STEP A2 — EXTRACT
    SaaSVersion_V174.zip extract karein, phir UPDATE.bat

STEP A3 — VERIFY (Ctrl+F)

      FILE                            SEARCH FOR
      ------------------------------  --------------------------
      VERSION                         V174
      src\Services\Sync.php           BAND HO CHUKA BILL SYNC SE
      src\Services\Sync.php           DOOSRE BUSINESS SE NA BADLE
      src\Services\ReportService.php  REPORT MEIN BHI SIRF POORE

STEP A4 — CHALA KAR DEKHEIN
    1. Ek bill poora karein (band karein)
    2. Thora intezar karein taake sync chal jaye
    3. Hold Bills kholein — wo bill us mein NAHI hona chahiye,
       na abhi, na agle sync ke baad
    4. Reports -> Sale Summary kholein
       -> total sirf poore huay bills ka hona chahiye
    5. Reports -> Void / cancelled bills
       -> cancel kiye hue bill ab yahan nazar aane chahiyen

Madad: Wabwar Software House — +92 342 5095104

// build: V174 build 2026-10-28

============================================================
 V164 — OFFLINE LAUNCHER SYSTEM TRAY MEIN
 Wabwar Software House                     build 2026-10-25
============================================================

Sirf ek cheez: software chalne ke baad wo kaali CMD window chhup jati
hai aur us ki jagah TRAY ICON (neeche daayen, ghadi ke paas) aa jata
hai. Software ke kisi aur hisse ko haath nahi lagaya — POS, bill, FBR,
stock, closing, menu, sync, sab V163 jaise hain.

SIRF TEEN FILES BADLIN:
    VERSION
    START_OFFLINE.bat
    tools\start_offline.ps1


############################################################
 ***  PEHLE YEH PARHEIN — IS BAAR MAIN NE TEST NAHI KIYA  ***
############################################################

Yeh Windows aur PowerShell ka kaam hai. Mere paas Windows nahi hai aur
PowerShell is machine par install bhi nahi ho sakti, is liye yeh
tabdeeli maine CHALA KAR NAHI DEKHI — sirf likhi hai. Baqi har kaam
mein jo "chala kar dekha" ka saboot deta hoon, is baar nahi de sakta.

IS LIYE:
  1. Pehle EK computer par try karein, sab par nahi.
  2. Purani file ki copy pehle se rakh lein:
         tools\start_offline.ps1   ->   tools\start_offline.ps1.v163
     (ya V163 ka zip sambhaal kar rakhein — us mein purani file maujood
      hai.)
  3. Agar software START HI NA HO: purani file wapas rakh dein aur
     START_OFFLINE.bat bhi V163 wala. Software foran pehle jaisa chalne
     lagega. Phir mujhe batayein, main theek kar doon ga.

Script ke andar ek pehra laga hua hai: agar tray icon kisi wajah se ban
hi na sake (purana Windows, .NET ka masla, company ki policy), to
purana tareeqa hi chalta hai — window khuli rehti hai aur software
bilkul theek kaam karta hai. Magar yeh pehra us soorat mein kaam nahi
karega jab script mein hi koi likhne ki ghalti ho — isi liye upar wali
teen hidayat zaroori hain.


------------------------------------------------------------
 KAISE CHALEGA
------------------------------------------------------------
  1. START_OFFLINE.bat (ya Desktop ka shortcut) chalayein
  2. Window pehle ki tarah khulti hai — database, server, browser sab
     wahi tarteeb, saare paigham nazar aate hain
  3. "The software is running at http://localhost:8080" ke 3 second
     baad window CHHUP jati hai aur tray icon aa jata hai

  TRAY ICON PAR:
     DOUBLE-CLICK      -> window wapas saamne (log aur error parhne ke liye)
     RIGHT-CLICK       -> Open POS  |  Show window  |  Stop SmartPOS

  "Stop SmartPOS" wahi kaam karta hai jo pehle window band karne par
  hota tha: pehle poochta hai, phir server, sync aur MariaDB teenon
  theek se band karta hai.

SERVER KHUD MAR JAYE TO WINDOW KHUD SAAMNE AA JATI HAI. Har 5 second
dekha jata hai. Server gir jaye to tray par ittila aati hai aur window
khul jati hai, taake wajah parhi ja sake — warna chhupi hui window ke
sath user ko lagta rehta ke sab theek hai.

UPDATE KA PAIGHAM HO TO WINDOW CHHUPTI NAHI. Agar naya build para ho
("A NEW UPDATE IS AVAILABLE / DOWNLOADED AND READY"), to window khuli
rehti hai taake wo paigham nazar aaye. Tray icon phir bhi ban jata hai.


------------------------------------------------------------
 EK BAAT JAAN LEIN
------------------------------------------------------------
Window chhup jane ke baad software band karne ka SAHIH raasta sirf
tray ka "Stop SmartPOS" hai.

Agar koi Task Manager se zabardasti band kar de, to MariaDB chalta reh
sakta hai (kyunke saaf band karne wala hissa chalne ka mauqa hi nahi
milta). Yeh nuqsaan deh nahi — agli dafa start karne par launcher usi
chalte hue database ko dobara istemal kar leta hai ("Using the existing
database from: ..." wahi paigham). Bas jaan lein ke aisa ho sakta hai.

PowerShell ko `-STA` ke sath chalaya ja raha hai (START_OFFLINE.bat
mein). Tray icon (WinForms) ko yehi chahiye hota hai. PowerShell 5
waise bhi STA hota hai, magar saaf likh dena behtar hai — warna kisi
purane Windows par tray chup-chaap na bane.


------------------------------------------------------------
 TESTING
------------------------------------------------------------
  Jo main kar saka:
    PowerShell ki banawat (braces / parens / here-string)        OK
      - parens ka jo farq hai wo PEHLE se maujood tha (comments
        mein likhe hue brackets); mere hisse ne barabar jore
    Functions ek dafa define, sab jagah se bulai gayin           OK
    Application::Run aur ::Exit — dono ek ek dafa                OK
    START_OFFLINE.bat aur tools\start_offline.ps1 — dono source
      zip aur node wale offline package, dono mein jate hain     OK
    Baqi software par asar: 3 files ke ilawa kuch nahi badla     OK

  Jo main NAHI kar saka (Windows nahi hai):
    Tray icon waqai banta hai ya nahi
    Double-click / right-click ka chalna
    "Stop SmartPOS" ka cleanup
    Server girne par window ka khud khulna
  Yeh chaar cheezein aap ke PC par dekhni hain.


============================================================
 LAGANE KA TAREEQA
============================================================

PART A — OFFLINE PC  (asal kaam yahi hai)

STEP A1 — BACKUP (ek minute, magar zaroori)
    tools\start_offline.ps1  ki copy bana lein
    START_OFFLINE.bat        ki copy bana lein

STEP A2 — YE PURANI FILES DELETE KAREIN (apni source copy mein)
    VERSION
    START_OFFLINE.bat
    tools\start_offline.ps1

    KOI NAYI FILE NAHI HAI (README ke ilawa).

STEP A3 — EXTRACT
    SaaSVersion_V164.zip extract karein, phir UPDATE.bat
    (Koi migration nahi — database ko kuch nahi hota.)

STEP A4 — VERIFY (har file mein Ctrl+F)

      FILE                          SEARCH FOR
      ----------------------------  --------------------------
      VERSION                       V164
      START_OFFLINE.bat             -STA
      tools\start_offline.ps1       SYSTEM TRAY
      tools\start_offline.ps1       NotifyIcon
      tools\start_offline.ps1       Stop SmartPOS
      tools\start_offline.ps1       holdWindow

STEP A5 — CHALA KAR DEKHEIN
    1. Shortcut se chalayein -> window khule, sab paigham nazar aayen
    2. 3 second baad window chhup jaye, tray icon aaye
    3. Tray icon par double-click -> window wapas
    4. Right-click -> "Open POS" -> browser khule
    5. Right-click -> "Stop SmartPOS" -> poochhe, phir sab band
    6. Dobara chalayein -> theek khule

    Agar STEP 1 par hi software na chale -> backup wapas rakh dein
    (STEP A1) aur mujhe batayein.


PART B — SERVER (Railway)

    Is tabdeeli ka cloud se koi taaluq nahi — launcher sirf offline PC
    par chalta hai. Phir bhi files dono jagah ek jaisi rakhni hain, is
    liye deploy kar dein. Cloud par koi farq nazar nahi aayega.

Madad: Wabwar Software House — +92 342 5095104

// build: V164 build 2026-10-25

============================================================
 V153 — NAYA INVOICE DESIGN, KHUD BANNE WALA PARCHA,
        AUR CUSTOMER KA APNA DOWNLOAD
 Wabwar Software House
============================================================

AAP NE TEEN BAATEIN KAHIN:

  1. "Invoice ka design more professional kar do ... pure english"
     -> Design B1 (Modern Statement, clean header) lag gaya.
        Poore parche par ek bhi Roman Urdu lafz nahi.

  2. "orignal logo add kro"
     -> Logo ka khana aa gaya (teen darje, neeche tafseel).
        AAP KO EK CHHOTA SA KAAM KARNA HAI — STEP A5 dekhein.

  3. "due date waly din auto invoice generate ho jay and customer ko
      show ho wahan se download kar sky"
     -> Dono: parcha ab khud banta hai (chahe hum console na kholen),
        aur customer apni screen se seedha PDF download karta hai.


------------------------------------------------------------
 1) INVOICE KA NAYA DESIGN
------------------------------------------------------------
Safed header, logo apne ASLI rangon mein (pehle wale navy band par
logo ko zabardasti safed karna parta — us se logo mar jata tha).
Daayein Invoice number aur UNPAID / PAID / OVERDUE ka pill.

Neeche sab se pehle BARE HARFON MEIN "AMOUNT DUE PKR 7,000" —
customer ko sab se pehle yehi dekhna hota hai. Phir Billed to,
service period, table, totals ka card, "How to pay" ke cards, aur
footer.

TIN FAISLE JO IS SAFHE KO BAQI APP SE ALAG RAKHTE HAIN:

  - shared.css YAHAN NAHI LAGTI.
    Yeh app ki screen nahi, DASTAVEZ hai. Agar shared.css ke colour
    variables use hote to wahi parcha do customers ko do rang ka
    jata (theme/dark mode), aur kaagaz par teesra. Ab poora CSS isi
    file ke andar hai — har machine par, har theme par, kaagaz par:
    ek jaisa.

  - NTN KAHIN NAHI CHHAPTA. Aap ne mana kiya tha. Super Admin ke
    form se bhi khana hata diya gaya. (Dukan ka apna NTN alag cheez
    hai — wo Settings mein hai aur bill par jaisa tha waisa hi hai.)

  - TAREEKH ANGREZI MEIN: "15 Oct 2026", na ke "2026-10-15".
    Database se doosri shakal aati hai; wahi chhapte to parcha
    software ka log lagta, dastavez nahi.


------------------------------------------------------------
 2) LOGO — TEEN DARJE
------------------------------------------------------------
Parcha logo is TARTEEB se dhoondta hai:

   1. public/assets/wabwar-logo.png     <- SAB SE MEHFOOZ
   2. Payment details -> "Logo URL"     <- aap ka diya hua link
   3. harfi wordmark (WABWAR)           <- aakhri sahara

Pehla kyun behtar hai: wo package ke ANDAR hota hai. Customer ka
internet band ho, ya wabwar.pk slow ho, ya kabhi band ho — parche par
logo phir bhi aata hai. URL wala raasta har dafa website par depend
karta hai.

Main yahan se aap ki website tak nahi pohanch sakta (is container ka
internet sirf npm/pip/GitHub tak khula hai), is liye logo khud daal
nahi saka. STEP A5 mein do line ka kaam hai.


------------------------------------------------------------
 3) PARCHA AB KHUD BAN JATA HAI
------------------------------------------------------------
PEHLE: auto-invoicing sirf DO jagah chalti thi —
   (a) Super Admin ka dashboard khulne par
   (b) `scripts/run_invoicing.php` ke cron par

Dono HUM PAR thin. Us hafte console na kholein aur cron na laga ho,
to due date aa kar guzar jati aur customer apni Activate screen par
kuch bhi na paata — na raqam, na parcha, na download. Bas phone.

AB: customer ka apna safha khulte hi us ke business ka parcha
yakeeni bana diya jata hai. Yeh sasta aur mehfooz hai:
   - expiry abhi 3 din se door ho -> kuch nahi hota
   - us ke zimme pehle se koi baqaya parcha ho -> wahi, naya nahi
Yani roz kholne se roz naya parcha NAHI banta.

SIRF CLOUD PAR. Offline node par parcha banta to invoice number
cloud ke number se takra jate (dono apni ginti chalate), aur billing
poori tarah portal ka kaam hai.

Cron phir bhi laga lein — us se WhatsApp bhejne ka waqt pata chalta
hai chahe customer login na kare:
    php scripts/run_invoicing.php


------------------------------------------------------------
 4) CUSTOMER KHUD DOWNLOAD KARTA HAI
------------------------------------------------------------
Activate / Renew screen par ab do jagah:

   Amount due ka card  ->  [Open invoice]  [Download PDF]
   Your invoices table ->  har line par    [Open]  [PDF]

"Download" ka matlab: wahi link magar `&print=1` ke saath. Safha
khulte hi browser ka print dialog khud khul jata hai, aur wahan se
"Save as PDF". Phone par bhi yehi chalta hai.

SERVER PAR PDF KYUN NAHI BANAYA: us ke liye poori library chahiye
hoti hai aur har machine par font missing ka alag rona. Browser ka
apna print engine har jagah mojood hai aur hamesha sahi chhapta hai.
Ek kam cheez tootne ko.

Ek aur cheez theek hui: Overdue wale parche par `tag bad` class lagi
hui thi — shared.css mein aisi koi class hai hi nahi (`tag danger`
hai). Is wajah se jo parcha SAB SE ZYADA tawajjo maangta hai wahi
bilkul saada nazar aata tha.


------------------------------------------------------------
 5) EK PURANA BUG JO TESTING MEIN PAKRA GAYA
------------------------------------------------------------
Fresh install par yeh mila:

   SQLSTATE[HY000]: 1267 Illegal mix of collations
   (utf8mb4_unicode_ci) and (utf8mb4_general_ci) for '='

Wajah: `migrate_collation` har table ko utf8mb4_unicode_ci par le
jati hai, magar MariaDB us column ko badalne se inkaar kar deti hai
jo kisi FOREIGN KEY mein shamil ho. Script wo table skip kar ke aage
barh jati hai — aur yehi theek hai, rukna poore deploy ko mar deta.

MASLA YEH NAHI KE EK TABLE PEECHE REH GAYI. MASLA YEH HAI KE KISI KO
PATA NAHI CHALTA. `tenants.id` general_ci par reh jaye aur
`invoices.tenant_id` unicode_ci par chala jaye, to har wo JOIN jo in
dono ko milata hai CHALTE HUE TOOT jata hai — deploy ke mahine baad,
kisi ek screen par, bina kisi wajah ke. Hamara invoice ka auto-banna
theek isi tarah khamoshi se nakaam ho raha tha.

Ab `scripts/check_collation.php` har deploy par dekhti hai. Deploy
log mein `COLLATION_OK` likha ho to joins mehfooz hain; warna wo
columns gina deti hai jo peeche reh gaye. Yeh deploy KABHI NAHI
ROKTI — chetawni hai, deewar nahi.

AAP KE RAILWAY (MySQL 8) PAR YEH MASLA SHAYAD HAI HI NAHI: MySQL FK
columns ko bakhushi convert kar deta hai. Yeh pehra sirf is liye hai
ke agar kabhi ho, to khamoshi se na ho.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    approved_ui\invoice.html
    approved_ui\activate.html
    approved_ui\super_admin.html
    approved_ui\retail\invoice.html      <- YEH AB WAPAS NAHI AAYEGI
    public\api.php
    public\router.php
    src\Services\Licence.php
    src\Services\PlatformSetting.php
    src\Services\SelfService.php
    src\Services\Platform.php
    scripts\migration_order.php

    YE NAYI HAI — delete karne ki zaroorat nahi:
    scripts\check_collation.php

    `approved_ui\retail\invoice.html` KYUN HATAI:
    invoice.html PUBLIC page hai (login nahi maangta), aur router
    public pages HAMESHA `approved_ui/` se uthata hai — chahe tenant
    retail ho. Yani retail wali copy kabhi serve hoti hi nahi thi.
    Do copies sirf drift paida karti hain: ek ko theek karte, doosri
    purani pari rehti.

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V153.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG MEIN YEH DEKHEIN
        MIGRATIONS_OK
        BILLING_OK
        MODULE_GROUPS_OK
        SETTINGS_CHECK_OK
        OFFLINE_TOOLS_OK
        COLLATION_OK                <- V153 ki nayi line

STEP A4 — VERIFY (har file ke liye search string — Ctrl+F)

      FILE                                SEARCH FOR
      ----------------------------------  --------------------------
      VERSION                             V153
      approved_ui\invoice.html            Modern Statement
      approved_ui\invoice.html            __logoFail
      approved_ui\activate.html           Download PDF
      approved_ui\super_admin.html        logo_url|Logo URL
      public\api.php                      'download'=>$lnk
      public\router.php                   kabhi serve hoti hi nahi
      src\Services\Licence.php            'logo'
      src\Services\SelfService.php        PARCHA KHUD BAN JAYE
      scripts\check_collation.php         COLLATION_OK
      scripts\migration_order.php         check_collation

STEP A5 — LOGO LAGAIEN (yeh sirf AAP kar sakte hain)

    RAASTA 1 (behtar — logo package ke andar):
      a. Apna logo yahan se download karein:
           https://wabwar.pk/imag/WABWAR%20LOGO%20final.png
      b. Us ka naam badal kar `wabwar-logo.png` rakhein.
      c. File yahan rakh dein:
           public\assets\wabwar-logo.png
      d. Deploy karein. Bas.
      (Transparent background wala PNG, lagbhag 400x120 px, theek hai.)

    RAASTA 2 (jaldi hai to):
      Super Admin -> Activations -> "Payment details" -> "Logo URL"
      mein poora link daal dein:
           https://wabwar.pk/imag/WABWAR%20LOGO%20final.png
      Save. Yeh chalta hai, magar har dafa website par depend karta
      hai.

    Dono na karein to parche par `WABWAR` harfi wordmark aayega —
    khali kabhi nahi rahega.

STEP A6 — BROWSER MEIN CHECK
    1. Super Admin -> Activations -> Payment details:
       "Logo URL" aur "Aap ka pata" ke khane nazar aayen, NTN ka na ho.
    2. Super Admin -> Invoices -> kisi parche par "View":
       naya design khule, logo aaye, kahin Roman Urdu na ho.
    3. Kisi aise business se login karein jiski expiry 3 din ke andar
       ho -> Activate / Renew par "AMOUNT DUE" ka card aur
       [Open invoice] [Download PDF] dono buttons.
    4. "Download PDF" dabayein -> print dialog khud khule -> Save as
       PDF -> ek safhe ka saaf parcha.


============================================================
 PART B — OFFLINE CUSTOMERS
============================================================
Customer sirf UPDATE.bat chalaye.
Invoice aur billing poori tarah cloud (portal) ka kaam hai — node par
koi tabdeeli nahi. Jaan boojh kar: offline node par parcha banta to
invoice number cloud ke number se takra jate.


============================================================
 TESTING JO KI GAYI
============================================================
  - Taza database (93 tables, 32 migrations) par poora flow
  - Teen business: expiry 2 din baad / 40 din baad / 5 din pehle
    guzri hui
  - Super Admin ko BILKUL HAATH LAGAYE BAGHAIR customer login kiya
    -> parcha khud ban gaya (INV-2026-0001), amount card par PKR 7,000
  - Expire hue business se login -> login HUA, Activate par pohancha,
    parcha khud bana (INV-2026-0002, PKR 5,000), download link mojood
  - Invoice ka safha: UNPAID, PAID (sabz pill, "Amount paid",
    "How to pay" gayab), ghalat token, aur bina token — chaaron sahi
  - Asli PDF banwa kar dekha: EK safha, sahi margins, footer do
    column mein, logo sahi jagah
  - Logo ke teeno darje alag alag aazmaye:
      local file mojood      -> local chala
      sirf Logo URL          -> URL chala
      dono nahi              -> WABWAR wordmark
  - Poore safhe par Roman Urdu ki talashi: sifar
  - 87 screens dono verticals mein: sab HTTP 200, koi redirect nahi,
    koi JavaScript error nahi
  - Auto-invoicing teen dafa chalai -> teeno dafa created=0 (dobara
    parcha nahi banta)
  - check_collation: saaf DB par COLLATION_OK; jaan boojh kar ek
    column kharab kiya -> COLLATION_DRIFT ne pakra; theek kiya ->
    wapas COLLATION_OK

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
 V153 build 2026-10-12
------------------------------------------------------------

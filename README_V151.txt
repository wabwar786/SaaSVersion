============================================================
 V151 — BILLING, INVOICES, AUR EXPIRY PAR KHULA DARWAZA
 Wabwar Software House
============================================================

AAP KA SAWAL: "lumsum monthly amount kahan se set hoti hai?"
JAWAB: pehle KAHIN SE BHI theek se nahi hoti thi. Teen alag jagah
thin aur teeno ka aapas mein koi rabta nahi:

   subscription_plans           -> qeematon ki fehrist, magar KABHI
                                   istemal hi nahi hoti thi (plan_id NULL)
   billing settings ka
   monthly_price                -> ek global number, sirf customer ki
                                   screen par LIKHA jata tha
   tenant_subscriptions.amount  -> sirf tab bharta jab renew karte waqt
                                   raqam type ho — yani payment ka
                                   record, rate ka nahi

Ab har business ka apna rate hai, aur us se har mahine ka apna parcha.


------------------------------------------------------------
 1) HAR BUSINESS KA APNA RATE
------------------------------------------------------------
Super Admin -> All Businesses -> Licence -> "Billing rate":

    Amount (PKR)      |  Cycle (per month / per year)
    WhatsApp number   |  Note

Kisi bhi waqt kam ya zyada karein. Har tabdeeli mehfooz hoti hai
(kis ne, kab, kitne se kitna) — paisay ka maamla hai.

AHEM: rate badalne se PURANE parche nahi badalte. Invoice us waqt ke
rate ki jami hui copy hai. Warna bheja hua parcha aur apna record
alag ho jate.


------------------------------------------------------------
 2) INVOICES
------------------------------------------------------------
Nayi screen: Super Admin -> Invoices

    Upar teen ginti:  Unpaid (ginti + raqam) | Overdue | Is mahine wasool
    Filter:           Unpaid / Paid / Cancelled / All
    Har parche par:   View · WhatsApp · Mark paid · Amount · Cancel
    Aur:              "New invoice" (koi bhi business, kitne bhi mahine)

Sidebar par baqaya parchon ki ginti nazar aati rehti hai.

INVOICE KAISA LAGTA HAI
Ek link: /invoice.html?t=<token>. Khulte hi poora parcha — Wabwar ka
header, NTN, business ka naam, period, raqam, tax (0 ho to line
chhapti hi nahi), bank/Easypaisa ki tafseel, aur upar bara sa
UNPAID / PAID / OVERDUE ka thappa. Us par "Print / Save as PDF".

LOGIN NAHI MAANGTA — jaan boojh kar. Parcha aksar us waqt jata hai
jab customer ka software expire ho chuka ho; wahan login karwana
poore maqsad ko khatam kar deta. Hifazat token se hai (32 harf, har
parche ka apna).


------------------------------------------------------------
 3) WHATSAPP
------------------------------------------------------------
DO RAASTE — dono chalte hain:

  (a) BINA KISI SETUP KE (default)
      Invoice par "WhatsApp" dabayein -> us business ka WhatsApp khul
      jata hai, paighaam pehle se likha hua (business, period, raqam,
      due date, parche ka link). Bas Send dabana hai.

  (b) KHUD-BA-KHUD (agar aap ki apni WA API ho)
      Payment details -> WhatsApp -> API URL + API key daal dein.
      Phir "WhatsApp" dabate hi khud chala jata hai.

EXPIRY SE 3 DIN PEHLE parcha KHUD ban jata hai (Super Admin console
khulte hi, ya `php scripts/run_invoicing.php` se — rozana cron par
laga dein).

EK WAQT MEIN EK HI BAQAYA PARCHA. Yeh shart na hoti to bhoolay hue
business ka har din naya invoice ban jata.


------------------------------------------------------------
 4) POORA CHAKKAR
------------------------------------------------------------
    expiry - 3 din   ->  invoice khud bane (UNPAID)
                     ->  WhatsApp (khud ya ek click se)
    customer paisay bheje
                     ->  apne software mein Activate/Renew se TXN bheje
    aap "Mark paid" dabayein
                     ->  invoice PAID
                     ->  licence khud barh jaye (months ke hisaab se)
                     ->  PAID wala parcha dobara WhatsApp (raseed)


------------------------------------------------------------
 5) EXPIRE HONE PAR LOGIN AB BAND NAHI HOTA
------------------------------------------------------------
Aap ne theek kaha: "yeh na ho k expire ho jay to login he na ho."
Pehle expiry par darwaza hi band tha — customer renew bhi nahi kar
sakta tha, apna hisaab bhi nahi dekh sakta tha, bas phone kar sakta
tha.

Ab taqseem saaf hai:

    LOGIN     ->  khula (sirf SUSPENDED par band — wo humara apna
                  faisla hota hai, expiry ka hadsa nahi)
    SOFTWARE  ->  band: har screen seedha Activate par, aur har API
                  band (402) siwaye un ke jin se renew hota hai

Yani andar aa kar bande ko sirf ek hi cheez milti hai — renew ka
raasta, apne invoices, aur bank ki tafseel.

API ka taala alag se lazmi tha: router sirf .html rokta hai. Agar
API khuli rehti to POS ka khula hua tab expiry ke baad bhi bill
kaatta rehta — aur wo bill cloud par bhi chala jata.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    VERSION
    public\api.php
    public\router.php
    src\Auth.php
    scripts\migration_order.php
    approved_ui\super_admin.html
    approved_ui\activate.html

    YE NAYI HAIN — delete karne ki zaroorat nahi:
    src\Services\InvoiceService.php
    src\Services\PlatformSetting.php
    scripts\migrate_billing.php
    scripts\run_invoicing.php
    approved_ui\invoice.html
    approved_ui\retail\invoice.html

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V151.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG
        MIGRATIONS_OK
        BILLING_OK                  <- nayi (columns_added=... )
        MODULE_GROUPS_OK
        SETTINGS_CHECK_OK
        OFFLINE_TOOLS_OK

STEP A4 — VERIFY (har file ke liye search string)
      FILE                                SEARCH FOR (Ctrl+F)
      ----------------------------------  --------------------------
      VERSION                             V151
      src\Services\InvoiceService.php     ensureForExpiry
      src\Services\PlatformSetting.php    class PlatformSetting
      scripts\migrate_billing.php         BILLING_OK
      approved_ui\invoice.html            HOW TO PAY
      approved_ui\super_admin.html        loadInvoices
      public\api.php                      licenceOpen
      src\Auth.php                        EXPIRY PAR AB LOGIN NAHI

STEP A5 — PEHLI DAFA KYA SET KARNA HAI
    1. Super Admin -> Activations -> "Payment details"
         - bank / Easypaisa ki tafseel
         - Tax % (0 rakhein to invoice par tax ki line nahi aayegi)
         - NTN, aur invoice ka note
         - WhatsApp API (khali chhor dein to wa.me se khud bhejein)
    2. Har business par: All Businesses -> Licence -> Billing rate
         - amount, cycle, aur us ka WhatsApp number
    3. Rozana ka cron (optional magar behtar):
         php scripts/run_invoicing.php

STEP A6 — BROWSER MEIN CHECK
    - Invoices screen par teen ginti aur list
    - Kisi parche par "WhatsApp" -> WhatsApp khule, paighaam bhara hua
    - "Mark paid" -> licence barhe, parcha PAID ho
    - Invoice ka link kisi doosre browser (bina login) mein kholein


============================================================
 PART B — OFFLINE CUSTOMERS
============================================================
Customer sirf UPDATE.bat chalaye.
Invoice aur billing cloud (portal) ka kaam hai — node par koi
tabdeeli nahi.


============================================================
 TESTING JO KI GAYI
============================================================
  - Rate PKR 12,000/month set kiya -> invoice khud usi se bana
    (INV-2026-0001, period aage ka)
  - Invoice ka link bina login khula; ghalat token par saaf inkaar
  - "Mark paid" -> expiry 2026-10-10 se 2026-11-12, parcha PAID,
    reference aur payment ka record dono mehfooz
  - Auto-invoicing teen dafa chalai -> 2 bane, phir 0 (dobara nahi)
  - EXPIRE hone par: login HO GAYA, seedha Activate par pohancha,
    `menu-items` jaisi API 402 (licence_expired) se bandh, magar
    payment-info / my-invoices khuli rahin
  - Paid hote hi customer wapas andar (index.html, API chal rahi)
  - 98 screens dono verticals mein: sab HTTP 200, koi redirect nahi,
    koi JavaScript error nahi

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
 V151 build 2026-10-12
------------------------------------------------------------

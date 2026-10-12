============================================================
 V152 — RATE CUSTOMER TAK, AUR DONO SCREENS KA DESIGN
 Wabwar Software House
============================================================

 1) ASAL MASLA: RATE CUSTOMER TAK POHANCHTA HI NAHI THA
------------------------------------------------------------
Aap ne restaurant ka rate 7,000 set kiya, magar customer ki screen
par 3,000 likha aata raha.

Wajah: wo screen platform ki GLOBAL qeemat (`monthly_price`, jo
Payment details mein 3000 pari thi) dikhati thi — business ka apna
`billing_amount` wahan jata hi nahi tha. Do alag number, do alag
jagah — aur customer wahi bhejta jo usay screen par dikhta.

AB: customer ko us ka apna rate dikhta hai. Aur agar koi parcha
baqaya ho to us ki ASAL raqam (invoice ka total) sab se upar,
bare harfon mein, invoice ke link ke saath.


 2) CUSTOMER KI SCREEN KA DESIGN
------------------------------------------------------------
Pehle: do column, baayein aadha safha khali, aur sab se ahem baat
(kitne paisay) ek lambi fehrist ki pehli line.

Ab:
    PKR 7,000              <- sab se upar, bare harfon mein
    Har mahine · agli renewal 2026-12-11
    [Invoice kholein]      <- jab koi parcha baqaya ho

Us ke neeche: paisay kahan bhejne hain (sirf wo khane jo bhare
hue hain), phir "paisay bhej diye" wala form, aur sab se neeche
customer ke apne invoices.


 3) SUPER ADMIN — "EDIT OPTION NAHI MIL RAHA"
------------------------------------------------------------
Button ka naam "Renew" tha, is liye kisi ko yeh nahi soojhta tha
ke RATE bhi wahin set hota hai. Ab:

  - button ka naam: "Renew"  ->  "Billing"
  - All Businesses mein "Rate" ka apna column
      PKR 7,000 /mo        (aur baqaya ho to saath likha)
      rate set nahi        (laal — us business ka parcha kabhi
                            banta hi nahi aur pata nahi chalta)
  - Expiry ke neeche "60 din baqi" / "5 din guzar gaye"
  - Licence dialog mein "Billing rate" ab SAB SE UPAR


 4) "ALL BUSINESS NAHI AA RHY YAHAN"
------------------------------------------------------------
Invoices ka safha INVOICES ki list thi, businesses ki nahi — aur
parcha sirf us business ka banta hai jis ki expiry qareeb ho.

Ab filter mein ek naya view: "Businesses — rate aur baqaya".
Har business nazar aata hai, chahe us ka parcha bana ho ya nahi:

   Business | Rate | Expiry (din baqi) | Baqaya | [Rate/Billing] [Invoice banayein]

Upar teen ginti: kitne businesses, kitnon ka rate set nahi, aur
kul baqaya. Tarteeb: jin ka rate set nahi wo sab se upar, phir jin
ki expiry sab se pehle khatam ho rahi hai.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    VERSION
    src\Services\SelfService.php
    src\Services\Platform.php
    approved_ui\super_admin.html
    approved_ui\activate.html

    (Koi nayi file nahi.)

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V152.zip extract karein, phir Railway par deploy.

STEP A3 — VERIFY (har file ke liye search string)
      FILE                              SEARCH FOR (Ctrl+F)
      --------------------------------  --------------------------
      VERSION                           V152
      src\Services\SelfService.php      own_rate
      src\Services\Platform.php         unpaid_total
      approved_ui\activate.html         amt-big
      approved_ui\super_admin.html      loadBillingList

STEP A4 — BROWSER MEIN CHECK
    1. All Businesses -> har row par "Rate" ka column aur "Billing"
       ka button
    2. Billing -> upar "Billing rate" -> 7000 -> Save rate
    3. Us business mein login kar ke Activate/Renew kholein ->
       upar PKR 7,000 likha aaye (3000 nahi)
    4. Invoices -> filter "Businesses" -> dono businesses nazar aayen


============================================================
 TESTING JO KI GAYI
============================================================
  - Rate 7,000 set kiya -> customer ki screen par PKR 7,000
    (payment-info: own_rate=7000, monthly=7000, yearly khali)
  - Rate 0 wale business par laal "rate set nahi"
  - Businesses view: dono businesses, rate/expiry/baqaya ke saath
  - Licence dialog ki tarteeb: Billing -> New expiry -> Payment
  - 98 screens dono verticals mein: sab HTTP 200, koi redirect
    nahi, koi JavaScript error nahi

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
 V152 build 2026-10-12
------------------------------------------------------------

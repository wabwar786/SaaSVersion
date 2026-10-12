============================================================
 V150 — POORA RENEWAL SYSTEM
 Wabwar Software House
============================================================

PEHLE YEH SAAF KAR DEIN — JO PEHLE SE CHAL RAHA THA
Renew ki screen pehle se wahi poochhti hai jo aap ne kaha:
transaction ID, kitne month, raqam, tareeqa, tareekh, phone.
Aur Super Admin ki Activations se Approve karte hi licence barh
jata hai. Yeh sab test karke dekha gaya.

MASLA DOOSRI JAGAH THA — warning kisi ko dikhti hi nahi thi, aur
Super Admin ka popup kaam ka nahi tha.


------------------------------------------------------------
 1) POS PAR WARNING KABHI AATI HI NAHI THI
------------------------------------------------------------
Restaurant POS ka licence banner, tablet pairing ka button aur
`qrFail` — teeno ghalti se `slipHtml()` ke ANDAR chipak gaye thay.
`slipHtml` sirf bill/KOT ka HTML banata hai, yani yeh code sirf tab
chalta jab kuch chhapta.

Nateeja: jis screen par cashier poora din baitha hai, wahan expiry
ki warning V65 se ab tak KABHI nahi aayi. (Browser mein tasdeeq:
`licBar` khali, `window.qrFail` undefined.)

Ab teeno script ke aakhir mein, `boot()` ke baad — page khulte hi.


------------------------------------------------------------
 2) RETAIL PAR BANNER THA HI NAHI
------------------------------------------------------------
Supermarket wale customer ko expiry se pehle koi khabar hi nahi
milti thi — na sidebar par, na POS par. Ek din aata aur darwaza
band.

Ab dono jagah wahi banner: 3 din pehle peela, expire par laal, aur
Renew ka seedha link.


------------------------------------------------------------
 3) LICENCE CONTROL POPUP — NAYA
------------------------------------------------------------
Pehle: sab khane ek patli qatar mein; "Extend by" aur "exact date"
aapas mein larte (dono bharein to kaun jeeta? saaf nahi tha);
transaction ID ka khana THA HI NAHI (halanke DB us ko sambhalti
hai); aur key banane wala hissa itna neeche ke nazar na aata.

Ab:
  - upar teen khane: Status / Expiry / Days left
  - mahine ke CHIP (+1, +3, +6, +1 year) — chip dabao to tareekh
    khud khali, tareekh daalo to chip khud band
  - "New expiry: 2027-04-12" pehle se nazar aata hai, aur guzri hui
    tareekh par laal warning
  - payment ka apna hissa: raqam, tareeqa, TRANSACTION ID, paid by
  - key wala hissa apni alag patti mein


------------------------------------------------------------
 4) ACTIVATIONS KA SAFHA
------------------------------------------------------------
  - Safha khulte hi list aa jati hai. Pehle yeh line thi hi nahi —
    safha KHALI rehta tha jab tak koi "Refresh" na dabaye.
  - Sidebar par PENDING ki ginti har safhe par nazar aati hai
    (pehle sirf us safhe par).
  - Reference / Method / Paid on / Contact / Expires now ab ek
    saaf grid mein (`.fgrid` ka CSS kahin likha hi nahi tha, is
    liye yeh sab ek lamba column ban jate thay).


------------------------------------------------------------
 5) DO CHHOTI CHEEZEIN JO AUDIT MEIN NIKLIN
------------------------------------------------------------
  - `restaurant_pos.html` ke andar `item_dialog.js?b=v18` hard-coded
    tha — V149 ka item-dialog fix POS tak pohancha hi nahi tha.
    Ab v19.
  - Router `APP_ROLE` do dafa bhej raha tha (V149 ki duplicate
    line) — hata di.


============================================================
 ROZANA KA RENEWAL — CUSTOMER KO YE BATAYEIN
============================================================

3 DIN PEHLE   Har screen par peeli patti:
              "Your subscription expires in 3 days. Please renew."
              Us par RENEW ka button.

RENEW PAR     Activate screen khulti hai:
                1. Paisay kahan bhejne hain (bank / Easypaisa / JazzCash)
                2. "Tell us you paid" — TRANSACTION ID, kitne month,
                   raqam, tareekh, phone

BHEJNE KE BAAD Customer ko "We have your payment details" dikhta hai.
               Wo kaam karta rehta hai (expiry tak).

AAP KI TARAF   Super Admin -> Activations (sidebar par ginti).
               Bank mein payment check karein, months chunein,
               "Approve & activate" — licence usi waqt barh jata hai
               aur customer ka banner gayab.

NET NA HO      Licence control -> "Make activation key" -> phone par
               likhwa dein. Customer Activate screen par daal deta hai.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    VERSION
    public\router.php
    approved_ui\super_admin.html
    approved_ui\restaurant_pos.html
    approved_ui\retail\pos.html
    approved_ui\retail\shell.js

    (Koi nayi file nahi.)

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V150.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG
        MIGRATIONS_OK
        MODULE_GROUPS_OK
        SETTINGS_CHECK_OK
        OFFLINE_TOOLS_OK

STEP A4 — VERIFY (har file ke liye search string)
      FILE                               SEARCH FOR (Ctrl+F)
      ---------------------------------  --------------------------
      VERSION                            V150
      approved_ui\super_admin.html       lc-chip
      approved_ui\restaurant_pos.html    LICENCE + TABLET
      approved_ui\retail\pos.html        Licence banner — V150
      approved_ui\retail\shell.js        Licence banner — V150
      public\router.php                  doosri dafa likh diya gaya

STEP A5 — BROWSER MEIN CHECK
    Super Admin -> All Businesses -> kisi business par "Licence":
      - upar Status / Expiry / Days left
      - "+ 6 months" dabayen -> neeche nayi tareekh nazar aaye
      - transaction ID ka khana mojood ho
    Super Admin -> Activations: safha khulte hi list, sidebar par ginti
    Customer: expiry 3 din mein ho to POS par bhi patti aaye


============================================================
 PART B — OFFLINE CUSTOMERS
============================================================
Customer sirf UPDATE.bat chalaye. Koi nayi file bhejne ki
zaroorat nahi.


============================================================
 TESTING JO KI GAYI
============================================================
  - Poora chakkar asli browser mein:
      3 din baqi  -> dashboard AUR POS dono par warning
      4 din baqi  -> koi warning nahi (theek)
      Renew dabaya -> Activate screen
      TXN JC-2200113 / 3 months bheji
      Super Admin: badge 1 -> Approve -> expiry 2026-10-14 se
                   2027-01-14, badge chhup gaya
      Customer ka banner gayab
  - Licence popup: chip aur tareekh ek doosre ko khali karte hain;
    guzri hui tareekh par laal warning; preview SERVER ki tareekh
    se banta hai (browser UTC par ho to bhi ek din ka farq nahi)
  - Payment ka record: PKR 18,000 / BANK / TXN-5551212 / Mubeen —
    DB mein mehfooz
  - 96 screens (54 restaurant + 42 retail): sab HTTP 200, koi
    JavaScript error nahi

------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
 V150 build 2026-10-12
------------------------------------------------------------

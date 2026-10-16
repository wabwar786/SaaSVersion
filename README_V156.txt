============================================================
 V156 — HOLD BILL MEIN WO ITEMS BHI AAYEN JIN KI KOT NAHI NIKLI
 Wabwar Software House                     build 2026-10-16
============================================================

AAP NE KAHA:
  "Hold bill par click karoon aur jo bhi table select karoon, us mein
   wo items bhi aani chahiyen jin ki abhi kitchen ki KOT nahi nikli."

Ab aisa hi hota hai. EK file, do line ka asal fix. Aap ka routine
jaisa tha bilkul waisa hai.


------------------------------------------------------------
 BUG — HOLD LIKHTA KAHIN AUR THA, PARHTA KAHIN AUR SE
------------------------------------------------------------
    likhai : api('records-save', {module:'held_bills'})  -> ui_records
    parhai : api('pos-holds') -> OpsService::holdBills() -> orders

V94 mein PARHAI asli `orders` par le jayi gayi thi (taake tablet ka
bheja hua bill counter par bhi dikhe), magar LIKHAI wahin `ui_records`
par reh gayi. Do alag daraazein.

Nateeja:
  KOT bhej di  -> asli order banta hai  -> Hold Bills mein DIKHTA tha
  KOT nahi     -> sirf ui_records mein  -> Hold Bills mein KABHI NAHI

Aap ne khud yeh pakra: "hold main sirf wo bill aa rha hai jo kitchen
ko send howa hai."

AB: hold wahi `pos-hold` use karta hai jo tablet use karta hai, aur
asli `orders` mein likhta hai. `pos-hold-open` saari items lauta deta
hai — `sent_qty` ki parwah kiye baghair — is liye Hold Bills se table
kholne par wo items bhi aa jate hain jin ki KOT nahi nikli thi.

Resume (`pos-hold-open`) aur cancel (`pos-hold-cancel`) pehle se asli
orders par thay. Sirf yeh aakhri sira juRna baqi tha.


------------------------------------------------------------
 JO BILKUL NAHI BADLA
------------------------------------------------------------
  * New Bill ka flow, table chunna, "Shift / Change Table" — jaisa tha
    waisa hi. Koi naya sawal, koi nayi screen nahi.
  * KOT, payment, print, bill numbering, FBR, tax
  * Retail — chhua hi nahi
  * Server par EK LINE bhi nahi badli. `pos-hold`, `pos-hold-open`,
    `pos-hold-cancel` teenon pehle se maujood aur chal rahe thay.
  * Koi migration nahi, koi schema change nahi.
  * `ui_records` ki purani held_bills rows — DELETE NAHI hueen. Jo
    bills us daraaz mein phanse hain, wo wahin salamat hain. Dekhne ke
    liye (offline PC ke mysql.exe par, ya Railway console par):

      SELECT id,
             JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.billNo')) bill_no,
             JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.table'))  table_name,
             JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.lines'))  lines,
             JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.total'))  total,
             updated_at
      FROM ui_records
      WHERE module_key='held_bills' AND deleted=0
      ORDER BY updated_at DESC;


------------------------------------------------------------
 TESTING (chala kar, guess nahi)
------------------------------------------------------------
  T-02 par 3 line: 1 ki KOT nikali, 2 ki NAHI            PASS
  "+ New Bill" -> purana bill Hold Bills mein aa gaya     PASS
  Hold list mein us bill ki TEENO line                    PASS
  Hold Bills -> T-02 kholne par SAARI 3 items wapas       PASS
  Un mein 2 wo bhi jin ki KOT nahi nikli (sentQty = 0)    PASS
  Payment -> parchi + bill hold list se nikal gaya        PASS

  Restaurant 43 screens -> 0 masla, 0 JS error


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    Windows ka "Replace or Skip" extraction ko toot-phoot deta hai,
    is liye pehle delete, phir extract.

    VERSION
    approved_ui\restaurant_pos.html

    Bas yehi do. Koi nayi file nahi.

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V156.zip extract karein, phir Railway par deploy.

STEP A3 — VERIFY (Ctrl+F)

      FILE                               SEARCH FOR
      ---------------------------------  ----------------------------
      VERSION                            V156
      approved_ui\restaurant_pos.html    HOLD AB ASLI ORDER MEIN
      approved_ui\restaurant_pos.html    api('pos-hold',payload())


============================================================
 PART B — OFFLINE PACKAGE (customer ka PC)
============================================================

Shop band hone ke baad UPDATE.bat. Rollback sirf purani file wapas
rakhna hai (backup\before-<tareekh> mein khud ban jati hai).


------------------------------------------------------------
 BROWSER MEIN CHECK (restaurant business se login)
------------------------------------------------------------
 1. Koi table chunein -> 3 item daalein
 2. Sirf 1 item ki KOT bhejein, baqi 2 ki NA bhejein
 3. "+ New Bill" dabayein
 4. Hold Bills kholein -> wo bill wahan mojood ho
 5. Us par Resume dabayein -> TEENO item wapas aayen,
    wo 2 bhi jin ki KOT nahi nikli thi

Madad: Wabwar Software House — +92 342 5095104

// build: V156 build 2026-10-16

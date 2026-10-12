============================================================
 V147 — ROZANA KA INVENTORY FLOW
 Wabwar Software House
============================================================

CHAR CHEEZEIN THEEK HUIN
------------------------
1) "The same item" ab naam nahi maangta.
   Aap ne theek kaha — jab same item chuna hai to matlab hi yeh hai
   ke YEH item khud stock hai. Naam ka khana hata diya; stock usi
   item ke naam se rakha jata hai. Aur agar inventory items pehle se
   mojood hon to ab PICKER khulta hai, "naya banao" wala khana nahi.

2) "+ New Purchase" har ASLI business mein toota hua tha.
   Code mein `addLine('chicken')` aur `addLine('coke_tin')` likha tha
   — yeh DEMO store ki ids hain. Asli business mein items ke UUID
   hote hain, is liye exception aata tha MODAL KHULNE SE PEHLE. Click
   par kuch nahi hota tha aur koi paighaam bhi nahi. Ab theek.

3) STOCK IN ab teen jagah se: Inventory, Purchasing, aur Day Closing.
   Pehle sirf Day Closing par chhupa hua tha. Dialog ab maujooda stock
   bhi dikhata hai pehle:
       In stock now: 55 KG  ·  today received 0, sold 0

4) Day Closing par rozana ka raasta likha hua hai:
       Subah: Stock In  ->  Din: Sale  ->  Shaam: Closing


============================================================
 ROZANA KA KAAM — CUSTOMER KO YE BATAYEIN
============================================================

SUBAH    Jo maal aaya -> + Stock In
         (Inventory ya Purchasing ya Day Closing, teeno jagah)
         Item, qty, rate. Rate khali chhoren to maujooda average
         cost chalta rahega.

DIN      POS se sale. Stock KHUD katta hai:
           - "The same item" wale item: jitne bikay, utne minus
           - "By recipe" wale item: har ingredient alag minus
             (yield aur waste % ke hisaab se)
         Kuch karna nahi parta.

SHAAM    Do closing:
           (a) Counter closing  -> Sale Point par shift close (cash)
           (b) Stock closing    -> Day Closing (Stock)

         Day Closing par har item ki ginti daalein. Jis item ki
         ginti na karen, us ka khana KHALI chhor dein — wo bilkul
         nahi chhua jayega.

         "Close the day" dabane se stock ginti ke barabar ho jata
         hai, aur wo adjustment Audit Log mein chala jata hai.


============================================================
 PART A — SERVER (Railway)
============================================================

STEP A1 — YE PURANI FILES DELETE KAREIN (apni source copy mein)

    VERSION
    WHATS_UPDATED.md
    public\item_dialog.js
    public\router.php
    approved_ui\purchasing.html
    approved_ui\inventory_creation.html
    approved_ui\stock_day_close.html
    approved_ui\retail\stock_day_close.html
    approved_ui\restaurant_pos.html

    YE NAYI HAI — delete karne ki zaroorat nahi:
    public\stock_in.js

STEP A2 — EXTRACT + DEPLOY
    SaaSVersion_V147.zip extract karein, phir Railway par deploy.

STEP A3 — DEPLOY LOG
        MIGRATIONS_OK
        MODULE_GROUPS_OK
        OFFLINE_TOOLS_OK

STEP A4 — VERIFY (har file ke liye search string)
    Notepad mein kholein, Ctrl+F:

      FILE                                SEARCH FOR (Ctrl+F)
      ----------------------------------  ----------------------
      VERSION                             V147
      public\stock_in.js                  window.StockIn
      public\item_dialog.js               the same name as the item
      approved_ui\purchasing.html         function invItems
      approved_ui\inventory_creation.html stockInBtn
      approved_ui\stock_day_close.html    dc-flow
      public\router.php                   stock_in.js

STEP A5 — BROWSER MEIN CHECK (business mein login kar ke)
    - Inventory      -> "+ Stock In" button nazar aaye
    - Purchasing     -> "+ Stock In" aur "+ New Purchase" dono;
                        New Purchase par modal KHULE (pehle nahi khulta tha)
    - Menu & Categories -> "+ Add item" -> "The same item" chunein:
                        agar inventory items hain to PICKER khule,
                        naam ka khana kahin na ho
    - Day Closing    -> upar Subah/Din/Shaam ki patti


============================================================
 PART B — OFFLINE CUSTOMERS
============================================================
Kuch bhejne ki zaroorat NAHI. Deploy ke baad customer sirf

    UPDATE.bat

chalaye. (Agar us ke folder mein UPDATE.bat nahi hai to pehle ek
dafa UPDATE_PATCH_V147.zip bhejein — 9 KB.)


------------------------------------------------------------
 Wabwar Software House   www.wabwar.pk   0342-5095104
------------------------------------------------------------

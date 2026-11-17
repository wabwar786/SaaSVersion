============================================================
 V201 — ONE TABLE ONE BILL, KITCHEN QTY CONTROL
 Wabwar Software House                     build 2026-11-17
============================================================

REQUIRES: V200 installed. Check: VERSION must say
"V200 build 2026-11-17" before you start, and
"V201 build 2026-11-17" after.

WHAT CHANGED (restaurant sale point only; online and offline):

 1. One table = one bill; any counter can open it.
    (Owner's rule.)
    - Clicking a table that already has items opens THAT bill on any
      counter, whoever started it. The amount goes into the account
      of the counter that closes (charges) the bill.
    - Two counters starting a bill on the same free table at the same
      moment: only one bill is made. The second counter gets
      "T-05 par pehle se bill #0068 khula hai — aap ke items usi bill
      mein daal diye gaye hain. Check kar ke dobara bhejein / charge
      karein." Its items are added to the table bill on its screen.
      (Before: 2-3 separate open bills on one table; table click,
      tablet and QR only reached the newest one.)
    - "Change Table" onto a table that already has a bill is refused:
      "T-05 par bill #0073 khula hai — yeh bill wahan nahi ja sakta.
      Doosra table chunein."
    - Takeaway / delivery held bills stay private to their cashier,
      as before.
    - Closing an account is no longer blocked by an open TABLE bill
      (any counter can open it). Open takeaway / delivery bills still
      block it (V200).

 2. Reducing a quantity already sent to the kitchen.
    Before: Karahi x2 sent to kitchen, cashier changed qty to 1 on the
    calculator — no password, no void log, kitchen not told; bill
    charged 1, kitchen made 2.
    Now: manager password (same as removing the line), void log
    record, kitchen slip "1 x Chicken Karahi -- CANCELLED (2 mein se)",
    kitchen screen shows "KAM: Chicken Karahi — Sirf 1 banayein".
    The slip prints once.

 3. Whole bill cancelled after KOT.
    Before: the ticket just disappeared from the kitchen screen; no
    slip. Now a cancel slip with the sent items prints on the kitchen
    printer.

 No database change. Reports, retail: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V201.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 6 files:

  VERSION
  RELEASES.json
  approved_ui\restaurant_pos.html
  public\api.php
  src\Services\PosService.php
  src\Services\OpsService.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V201.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V201 build 2026-11-17
  RELEASES.json                      "V201 build 2026-11-17", "for": ["RESTAURANT"]
  approved_ui\restaurant_pos.html    TABLE_BUSY_MOVE
  public\api.php                     'can_open'=>true
  src\Services\PosService.php        private static function tableFree(
  src\Services\OpsService.php        KAM:

  Every changed file also carries: V201 build 2026-11-17


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V201 one table one bill, kitchen qty control"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on each POS PC press Ctrl+F5 once.

Old data: if a table already has two open bills from before, both
can still be opened, saved and charged (the check runs only when a
NEW bill is made on a table or a bill is MOVED to a table).


############################################################
 TESTS — cloud-style server (8 workers) and shop-PC style
############################################################

 New (14 + 5 POS screen):
 - Two counters on free T-05 at the same moment: ONE bill; second
   told TABLE_BUSY; both counters' items end on that one bill.
   On screen: message shown, Naan x2 added to the T-05 bill.
 - Change Table onto busy T-05 refused; onto free T-02 works.
 - Cashier B clicks T-02 (made by A): opens it, same items; B
   charges: bill and cash in B's account, not A's.
 - Takeaway held bill of A still private.
 - A's close: blocked by takeaway bill only, not by table bill.
 - Karahi 2 -> 1: manager password asked; slip "(2 mein se)";
   kitchen screen "KAM: ... Sirf 1 banayein"; slip once only;
   void log saved.
 - Bill cancelled after KOT: slip "3 x Garlic Naan -- CANCELLED".

 Rush: 8 x 12 and 16 x 20 bills, counters + QR + tablets together
 (cloud style and shop style): all pass.

 Regression: POS screen 13/13, removed item 17/17 + 6/6, V199 10/10,
 V200 20/20 + 6/6, tablet 6/6, POS 13/13 + 10/10 (table rule test
 updated to the new owner rule), login 15/15, accounts & reports
 26/26, tax, stock, business isolation 9/9, owner app 19/19 + 22/22,
 all 34 reports, restaurant pages same as V200, retail 113/113 +
 149/149.

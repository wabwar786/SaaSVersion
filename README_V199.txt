============================================================
 V199 — KITCHEN CANCEL, HANDOVER SAFETY, DISCOUNT LIMIT
 Wabwar Software House                     build 2026-11-17
============================================================

REQUIRES: V198 installed. Check: VERSION must say
"V198 build 2026-11-17" before you start, and
"V199 build 2026-11-17" after.

WHAT CHANGED (restaurant only; online and offline):

 1. Kitchen is told when an item is cancelled.
    Item sent to the kitchen (KOT) and then removed from the bill with
    the manager password:
    - Kitchen screen (KDS) shows it as "CANCELLED: <item>" with the
      note "CANCELLED - mat banayein". Before, it stayed "NEW" and the
      kitchen cooked it.
    - A cancel slip prints on the same kitchen printer that got the KOT:
          *** CANCEL ***
          Bill #0033  (KOT-E24705BA)
          1 x Chicken Karahi  -- CANCELLED
          MAT BANAYEIN
    - Removing an item that never went to the kitchen: no slip.

 2. Handover: the old screen cannot charge a handed-over bill.
    Cashier A hands pending bills to Cashier B while one of them is
    still open on A's screen. Before, A could still charge it (money in
    A's drawer, bill in B's name). Now A gets "Bill badal gaya hai",
    A's screen clears, and only B can open and charge it.

 3. Discount is never more than the bill.
    1,000 discount on a 400 bill: bill 0 (as before) but the discount
    is saved as 400, not 1,000 — discount reports no longer show 600
    extra. Normal discounts unchanged.

 No database change. Reports, retail: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V199.bat into the project folder
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

  Where: your local PC. Extract SaaSVersion_V199.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V199 build 2026-11-17
  RELEASES.json                      "V199 build 2026-11-17", "for": ["RESTAURANT"]
  approved_ui\restaurant_pos.html    !resumeHold({id:id})
  public\api.php                     o.row_version=o.row_version+1
  src\Services\PosService.php        private static function kitchenCancel(
  src\Services\OpsService.php        CANCELLED - mat banayein

  Every changed file also carries: V199 build 2026-11-17


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V199 kitchen cancel, handover, discount limit"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on each POS PC press Ctrl+F5 once.


############################################################
 TESTS
############################################################

 New (10 of 10 + screen test):
 - Kitchen screen: removed Karahi CANCELLED, Biryani unchanged.
 - Cancel slip on the kitchen printer with bill no, KOT no, item.
 - Item never sent to kitchen removed: no slip.
 - Handover: A's old screen charge refused ("Bill badal gaya"),
   A cannot reopen it, B opens and charges it in his account.
   On the POS screen: message shown, A's screen cleared, bill still
   open for B, no JavaScript errors.
 - Discount 1,000 on 400: saved 400, bill 0, paid 0. On hold with
   service 100: saved 500, total 0. Discount 100 on 400: 300.

 Also checked in this audit (all correct, no change needed):
 - Tax 16% inclusive and exclusive with discount + service: hold
   total = bill total = payment; tax report and P&L match.
 - Stock: sale deducts, removed item not deducted, void returns
   stock, cancelled hold none, payment pressed twice deducts once.
 - All six payment buttons (Cash, Card, Raast, Easypaisa, JazzCash,
   Bank) save; reprint shows bill no, items, total.
 - Account open past midnight: both days' bills on the closing slip.

 Regression: POS rush 17/17, POS screen 13/13, removed item 17/17 +
 6/6, POS 13/13 + 10/10, login 15/15, accounts & reports 25/25,
 owner app 19/19 + 22/22, all 34 reports, restaurant pages same as
 V198, retail 113/113 + 149/149.

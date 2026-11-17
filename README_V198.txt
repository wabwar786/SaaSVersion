============================================================
 V198 — REPORTS, ACCOUNT CLOSING, TABLE STATUS
 Wabwar Software House                     build 2026-11-17
============================================================

REQUIRES: V197 installed. Check: VERSION must say
"V197 build 2026-11-17" before you start, and
"V198 build 2026-11-17" after.

HOW THIS WAS FOUND: one full test day on a test cashier — opening
5000, 13 bills of every kind (cash, card, Easypaisa, % and flat
discount, service charge, delivery, item removed after hold, item
removed after KOT, hold cancelled, paid bill voided, payment pressed
twice, one bill left open), 300 expense, account closed with 10,150
counted. Every report, the closing slip, dashboard and owner app
were compared with the known answer.

WHAT CHANGED (restaurant only):

 1. Expenses in "Shift / day closing" and "Account wise sale".
    They showed 0 (closing slip showed 300). An expense entered on the
    Expenses page now joins the account (shift) that user has open;
    and both reports count an expense without an account the same way
    the closing slip does (made while the account was open).
    Account wise Net is now right (8,080, was 8,380).

 2. "Shift / day closing": new column "Online / Wallet" (Easypaisa,
    JazzCash, bank). These were shown under "Credit" (udhaar).

 3. "Returns and refunds": only bills that were paid and then voided.
    A cancelled hold bill (never paid) is no longer shown as a refund.

 4. "Void / cancelled bills": the real reason and the person who
    voided / cancelled (was "-" and the cashier who made the bill).
    Cancelling a hold bill now records who cancelled it.

 5. "Order / invoice detail" and "Void" reports: item count does not
    count items removed from the bill.

 6. Dashboard "Running orders": only open bills (void / cancelled
    bills were counted).

 7. Table "Occupied": from the bill that is open on the table — on
    the POS table picker and the dashboard. Before, it read an old
    status that the POS never updates, so a busy table showed
    "Available". Reserved tables stay as they are.

 NOT A PROBLEM (checked): Cash book already labels the column
 "Card / online"; Closing History page shows no card column.

 No database change. POS billing, retail: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V198.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 6 files:

  VERSION
  RELEASES.json
  public\api.php
  src\Services\ReportService.php
  src\Services\PageData.php
  src\Services\ModuleBridge.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V198.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V198 build 2026-11-17
  RELEASES.json                      "V198 build 2026-11-17", "for": ["RESTAURANT"]
  public\api.php                     ' · by '
  src\Services\ReportService.php     online_sales
  src\Services\PageData.php          WHEN dt.status='OCCUPIED' THEN 'AVAILABLE'
  src\Services\ModuleBridge.php      $shiftId = null;

  Every changed file also carries: V198 build 2026-11-17


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V198 reports and closing fixes"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

Old data: expenses already saved without an account are counted by
time (rule 1), so old reports also show them. Old cancelled holds
have no "by" — they show the cashier, as before.


############################################################
 TESTS
############################################################

 Accounts & reports (25 of 25), on the test day above:
 - Closing slip, Shift / day closing report, Shift Management,
   Closing History: sale 8,380, cash 5,500, card 2,800,
   online 80, credit 0, expense 300, expected 10,200, counted
   10,150, variance -50 — all the same.
 - Account wise: expenses 300, net 8,080.
 - Refunds: only bill #10 (2,400). Voids: #10 "test void" by
   manager; #9 "Hold bill cancelled: test" by who cancelled.
 - Invoice detail: bills with a removed item show 1 item.
 - Dashboard: sale 8,380, 10 bills, running 1, occupied 1.
 - POS table picker: table with open bill Occupied; old status
   without a bill Available; Reserved stays Reserved.
 - Unchanged and correct: Sales summary, Payment methods, Sales
   by item, Profit & loss (expenses 500 = cashier 300 + owner 200),
   owner app.

 Regression: POS rush 17/17, POS screen 13/13, removed item 17/17 +
 6/6, POS 13/13 + 10/10, login 15/15, owner app 19/19 + 22/22, all
 34 reports run (and CSV), restaurant pages same as V197, retail
 113/113 + 149/149.

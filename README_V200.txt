============================================================
 V200 — SALE POINT: QR, TABLET, ACCOUNT CLOSING, MENU DELETE
 Wabwar Software House                     build 2026-11-17
============================================================

REQUIRES: V199 installed. Check: VERSION must say
"V199 build 2026-11-17" before you start, and
"V200 build 2026-11-17" after.

WHAT CHANGED (restaurant only; online and offline):

 1. QR / app order goes to its OWN bill.
    Before: "Accept" put the QR items into whatever bill the cashier
    had open (table T-02's food went into a walk-in's takeaway bill,
    and the table name was lost).
    Now: the cashier's open bill is not touched. The server puts the
    order on the table's open bill (quantities add up), or makes a new
    hold bill for that table. App orders (no table) get their own hold
    bill with the customer's name / phone. The toast says which bill:
    "Order accepted — bill #82 (T-02) mein add ho gaya".
    The same QR order cannot be accepted twice.
    Kitchen: as before, the items go to the kitchen when that bill is
    sent (KOT) or charged.

 2. Account cannot close with open bills.
    Before: closing the account with a held / KOT bill left that bill
    open but invisible — it did not show in the next account's Hold
    Bills ("bill gum").
    Now: "Close account" (POS) and Shift Management close show:
      "2 bill abhi khule hain: #0073 (T-02, 800), #0074 (Takeaway,
       400). Pehle inhen charge karein, cancel karein, ya Hand over
       se agle cashier ko dein — phir account band karein."
    Bills already stuck from before now show in that cashier's Hold
    Bills with a "PICHLA ACCOUNT" tag (other cashiers do not see them).

 3. Deleted menu item does not block a held bill.
    Before: bill on hold with Lassi, manager deletes Lassi from the
    menu -> bill could not be charged ("Menu item not found").
    Now the bill charges. A NEW bill still cannot use a deleted item.

 4. Tablet no longer wipes the counter's discount, service and tax.
    (Found while testing.) Counter gave 10% discount + service + 16%
    tax on a table bill; waiter added a Naan from the tablet -> all
    three became 0 (bill 2,710 -> 2,980). Now they stay; a percent
    discount is recalculated on the new amount (2,782).

 5. Tablet cannot overwrite newer items on the same table.
    (Found in the rush test.) A tablet that opened the table before a
    QR order / another tablet added items would send its old quantity
    and cut the newer items (e.g. Naan 5 -> 3; in rush 2 to 5 of 15
    tablet adds were lost). Now the tablet send is stopped with
    "Is table ka bill counter / QR se badla gaya — taaza bill khol
    diya, dobara check kar ke bhejein", and the fresh bill opens.

 6. Cashier no longer receives the business's sale figures.
    The dashboard data that every page loads now carries sale amounts
    only for users with Dashboard / Reports rights or managers. A
    cashier still gets running orders, kitchen and table counts.

 No database change. Reports, retail: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V200.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 9 files:

  VERSION
  RELEASES.json
  approved_ui\restaurant_pos.html
  approved_ui\restaurant_order_taker_tablet.html
  public\api.php
  public\router.php
  public\ops_db.js
  src\Services\PosService.php
  src\Services\OpsService.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V200.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                                         Search for
  -------------------------------------------  ------------------------------
  VERSION                                      V200 build 2026-11-17
  RELEASES.json                                "V200 build 2026-11-17", "for": ["RESTAURANT"]
  approved_ui\restaurant_pos.html              PICHLA ACCOUNT
  approved_ui\restaurant_order_taker_tablet.html  table_fresh
  public\api.php                               function pos_open_bills_msg(
  public\router.php                            ops_db.js?b=v200
  public\ops_db.js                             res.code === 'OPEN_BILLS'
  src\Services\PosService.php                  public static function qrAccept(
  src\Services\OpsService.php                  'rev' => (int)$o['row_version']

  Every changed file also carries: V200 build 2026-11-17


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V200 sale point QR, tablet, closing, menu delete"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: press Ctrl+F5 once on EVERY POS PC AND every waiter
tablet (both screens changed).


############################################################
 TESTS — cloud-style server (8 workers) and shop-PC style (1)
############################################################

 New (20 + 6 POS screen + 6 tablet screen, all pass):
 - Tablet keeps 10% (298 on 2,980), service 100, 16% tax; counter
   reopens with them and charges.
 - QR for empty T-02 -> new T-02 bill; 2nd QR -> same bill, Naan
   3+2=5; same QR twice refused; marked ACCEPTED. On the POS: walk-in
   cart unchanged, toast names the bill, table click opens it.
 - Close with open bills stopped (POS and Shift Management) with the
   list; old stuck bill shows as PICHLA ACCOUNT, not to others;
   after clearing, close works.
 - Held bill with deleted item charges (300); new bill refused.
 - Cashier dashboard data has no sale figures; owner full.
 - Tablet: sends 2, QR adds 3 (=5), tablet's old view send stopped
   (stays 5), fresh view +1 = 6; no JavaScript errors.

 RUSH (the main focus):
 - 6 counters x 10 sales + 12 QR accepts + 3 tablets x 5 adds, all
   at once: nothing refused, every table exactly ONE bill, all 12 QR
   items once, all 15 tablet adds there, no bill number twice, every
   subtotal = its items. 4 runs cloud style + 1 shop style: all pass.
 - 8 counters x 12 bills (3 runs) and 16 x 20 = 320 bills: all pass.

 Regression: POS screen 13/13, removed item 17/17 + 6/6, V199 10/10,
 POS 13/13 + 10/10, login 15/15, accounts & reports 26/26, tax,
 stock, business isolation 9/9, owner app 19/19 + 22/22, all 34
 reports, restaurant pages same as V199, retail 113/113 + 149/149.

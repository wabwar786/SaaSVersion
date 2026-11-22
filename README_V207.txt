============================================================
 V207 — SHOP PC AND CLOUD NEVER WORK ON THE SAME BILL
 Wabwar Software House                     build 2026-11-22
============================================================

REQUIRES: V206 installed. Check: VERSION must say
"V206 build 2026-11-18" before you start, and
"V207 build 2026-11-22" after.

Update the CLOUD and EVERY SHOP PC on the same day (cloud first).
The protection works on whichever side has V207; an old shop PC can
still charge a cloud bill until it is updated.

WHAT CHANGED (restaurant only; retail: no change):

 1. A bill is changed or charged only where it was made.
    Audit (real shop PC syncing to the cloud):
      - A held bill was charged on the cloud POS and also on the shop
        PC before they synced: Rs 560 bill, two payments (Rs 1,120),
        on both computers. Till and card report doubled.
      - The cloud closed a bill (Rs 960) while a waiter on the shop PC
        added a Naan: after sync the closed bill had Rs 1,040 of items
        and Rs 960 paid. The Naan (Rs 80) went unpaid.
    Now: a shop PC bill (number starts with the shop PC code, e.g.
    L9-0012) can be changed, sent to kitchen, cancelled or charged only
    on that shop PC. A cloud bill only on the cloud. The other side can
    still open and see it, and gets:
      "Yeh bill shop PC (L9) ka hai — wahan charge karein."
    This applies to everyone, the owner included. Tablets and QR orders
    on a table that has the shop PC's bill are refused the same way.

 2. Safety net for bills already caught in the middle (older versions):
      - A second payment that arrives by sync for an already paid bill:
        the FIRST payment stays; the second is taken out of sales and
        the till. The full payment row is kept in the new
        "Sync conflicts" list and in the Audit log (SYNC_CONFLICT).
        A second stock deduction for that bill is removed the same way.
        Both computers apply the same rule, so both end with the same
        payment.
      - An item added on one computer after the other computer closed
        the bill goes to a NEW held bill on the same table (number
        e.g. L9-0012/A), so it can be charged. Listed in Sync conflicts.
      - A quantity change to a line of a bill that is closed on the
        other side is not applied; listed in Sync conflicts.
    Sync conflicts list: Offline & Sync page, owner and manager only.

 3. "Sync now" on the shop PC no longer holds the counters.
    It ran the whole sync inside the button's request: with 300 bills
    waiting, every counter waited 1.7 seconds. Now the button wakes the
    background sync (the same one that runs every minute) and returns
    at once; the page shows "Syncing..." until it finishes. If the
    background sync is not running, the button syncs by itself as before.

 4. Sync rows the receiving side keeps on purpose (closed bill cannot
    be reopened, first close wins, closed line not changed) now count
    as done. Before, the sending shop PC retried them 3 times and
    showed "row(s) rejected by cloud".

 No new migration: the list's table (sync_bill_conflicts) is created
 automatically on first use, on the cloud and on each shop PC.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V207.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 8 files:

  VERSION
  RELEASES.json
  src\Services\Sync.php
  src\Services\PosService.php
  public\api.php
  approved_ui\restaurant_pos.html
  approved_ui\index.html
  approved_ui\offline_sync.html


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V207.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V207 build 2026-11-22
  RELEASES.json                      "V207 build 2026-11-22", "for": ["RESTAURANT"]
  src\Services\Sync.php              public static function resolveOrder
  src\Services\PosService.php        OTHER_SIDE
  public\api.php                     case 'sync-conflicts'
  approved_ui\restaurant_pos.html    Doosri jagah ka bill
  approved_ui\index.html             Sync started in the background - billing continues.
  approved_ui\offline_sync.html      id="confPanel"

  Every changed file also carries: V207 build 2026-11-22


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway) — first
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V207 bill only on its own side, sync conflict safety net, background Sync now"
      git push
  Railway deploys by itself.

OFFLINE (restaurant shops) — same day
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on each POS PC press Ctrl+F5 once.
POS > System > Version must show "POS V207 build 2026-11-22".


############################################################
 TESTS
############################################################

 New, with a real shop PC (own database, L9- numbers, real sync engine)
 and the cloud:
  - V207 tests 55/55: cloud refuses charge / hold / kitchen / cancel /
    tablet-table add of a shop PC bill (owner too) and the shop PC
    refuses a cloud bill; each side charges its own; old double charge
    (either side first) ends with one payment on both computers, same
    order, listed + Audit log, no ping-pong over more syncs; late item
    goes to a new held bill on the table on both computers and is
    charged on the shop PC; closed-line change not applied; normal sync
    of bills, lines and payments unchanged both ways; Sync now returns
    in 28 ms while 300 bills sync in the background (counters max
    61 ms); without the background sync it still syncs itself.
  - V207 screens 8/8: cloud POS shows "Doosri jagah ka bill" and clears
    the screen; owner sees the Sync conflicts list; shop PC Sync now
    shows "Sync started — billing continues".
  - Sync scenarios from the V207 audit: shop PC bills reach the cloud
    exactly; cloud down -> billing continues, nothing lost or doubled;
    numbers do not clash; cloud void reaches the shop PC.
  - Receipt vs database: 120 random bills, 0 differences.

 Regression (all pass): V205 25/25 + 5/5, V204 27/27 + 7/7, V203 36/36
  + 6/6, V202 21/21 + 9/9, V199 10/10, V200 20/20 + 6/6, V201 14/14 +
  5/5, removed item 17/17, rush 17/17, POS screen 13/13, tablet 6/6,
  login 15/15, business isolation 9/9, accounts & closing reports
  26/26, permissions matrix 3/3, owner app 19/19 + 22/22, retail
  113/113 + screen 149/149.

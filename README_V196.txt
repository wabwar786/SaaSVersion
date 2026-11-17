============================================================
 V196 — RESTAURANT POS: REMOVED ITEM STAYS REMOVED
 Wabwar Software House                     build 2026-11-17
============================================================

REQUIRES: V195 installed. Check: VERSION must say
"V195 build 2026-11-17" before you start, and
"V196 build 2026-11-17" after.

THE PROBLEM (customer report, reproduced in test):
 Remove an item from a bill, hold the bill, open it again — the
 removed item came back. Same after KOT and after "print bill".
 Worse: if the bill was closed like that, the bill total was right
 but the removed item stayed in the database as a sold line, so
 item-wise sale reports counted it.

 Cause: on hold / KOT / close the server wrote only the items in the
 cart. A line taken out of the cart was never touched in the
 database, so it stayed ACTIVE.

THE FIX:
 - When a bill is opened (resume, KOT, print), the POS remembers
   which database lines it showed. On the next hold / KOT / close,
   any of those lines that is no longer in the cart is marked VOID:
   qty 0, amount 0. It never comes back, is not charged, and is not
   counted in item reports, owner app, bill reprint or FBR invoice.
 - It is VOID, not deleted, because kitchen tickets and the void log
   point to that line, and offline sync carries updates (a delete
   would not reach the cloud).
 - Kitchen-sent items still need the manager password to remove,
   same as before.
 - An item a tablet added to the same bill meanwhile is NOT touched
   (it was never on this screen).
 - Old bills: no change. Retail: no change. No database change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V196.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 9 files:

  VERSION
  RELEASES.json
  approved_ui\restaurant_pos.html
  public\api.php
  src\Services\PosService.php
  src\Services\OpsService.php
  src\Services\FiscalService.php
  src\Services\ReportService.php
  src\Services\OwnerMobile.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V196.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V196 build 2026-11-17
  RELEASES.json                      "V196 build 2026-11-17", "for": ["RESTAURANT"]
  approved_ui\restaurant_pos.html    base_line_ids
  public\api.php                     WHERE oi.order_id=? AND oi.status='ACTIVE'");
  src\Services\PosService.php        function dropRemoved(
  src\Services\OpsService.php        'lid'      =>
  src\Services\FiscalService.php     WHERE oi.order_id=? AND oi.status='ACTIVE'");
  src\Services\ReportService.php     billWhere() . " AND oi.status='ACTIVE'
  src\Services\OwnerMobile.php       o.order_status='CLOSED' AND oi.status='ACTIVE'

  Every changed file also carries: V196 build 2026-11-17


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V196 removed POS item stays removed after hold"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on each POS PC press Ctrl+F5 once.

QUICK CHECK ON THE LIVE POS (browser):
  New bill -> add 2 items -> Hold -> open it from Hold Bills ->
  remove one item (x) -> Hold -> open it again.
  Only one item must be there.


############################################################
 TESTS
############################################################

 Server (17 of 17):
 - hold -> resume -> remove -> hold -> resume: item gone; hold total
   = remaining items; line VOID qty 0 in database.
 - closed bill: total right, reprint does not show removed item.
 - KOT, remove kitchen item, close: VOID, not charged, kitchen-sent
   qty kept on the line.
 - print bill (save), remove, close: removed line VOID.
 - note changed on a line: old line not left behind.
 - qty 3 -> 1, item removed then added back later: correct.
 - item added by tablet meanwhile: NOT removed.
 - request without the new field (tablet / old screen): no change.
 - line id of ANOTHER bill sent: that bill untouched.
 - bills of the run: sum of item lines = sum of bill subtotals.

 POS screen (6 of 6): remove with the X button, hold, resume — item
 does not come back; kitchen item removed with manager password
 stays removed; new bill never held; no JavaScript errors.

 Regression: POS server 13/13, POS screen 10/10, login 15/15,
 owner app 19/19 + 22/22, all 34 restaurant reports run, restaurant
 pages same as V195, retail 113/113 + 149/149.

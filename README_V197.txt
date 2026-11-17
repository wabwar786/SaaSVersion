============================================================
 V197 — RESTAURANT POS: SAFE IN RUSH TIME
 Wabwar Software House                     build 2026-11-17
============================================================

REQUIRES: V196 installed. Check: VERSION must say
"V196 build 2026-11-17" before you start, and
"V197 build 2026-11-17" after.

WHAT CHANGED (restaurant POS only; online and offline):

 1. One bill is never charged twice.
    Each new bill gets its own id the moment it starts. If the
    payment answer is lost on the network and the cashier presses
    Complete Payment again, the server finds the same bill and says
    "Bill #0123 pehle hi band (paid) ho chuka hai — dobara charge
    nahi hua". The cart is cleared. (Reprint from Duplicate.)

 2. A closed bill cannot be charged again.
    Counter A closed a held bill, counter B tries the same bill:
    refused with the same message. Before: a NEW bill was made.

 3. A removed item cannot come back from another screen.
    Every save raises the bill's version. If the bill was changed on
    another counter or a tablet after this screen opened it, the save
    is stopped: "Bill badal gaya hai" — the fresh bill opens on screen.

 4. A tablet item is never left unpaid.
    Waiter adds an item from the tablet while the counter has the
    bill open: counter's charge is stopped, the bill reopens with the
    tablet item, cashier charges the full bill.

 5. No failed or half-saved bill in rush.
    Saves of one branch now go one after another, with automatic
    retry on a database deadlock (cloud). A save is either complete
    or shows a clear error — never half.

 6. Unsaved bill comes back after reload.
    The bill on screen is kept in this PC's browser. After a reload,
    browser close or power cut, the POS asks "Pichla bill wapas
    laayein?" (Haan / Nahi). Works without internet.

 7. Tablet adds to the table's existing bill (fix of a V195 side
    effect): each tablet hold / KOT for a table that already had a
    bill was making a separate bill. Now it goes into the table's
    open bill again.

 No database change. Reports, retail, owner app: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V197.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 7 files
     (PosConflict.php is new — "not found" is fine):

  VERSION
  RELEASES.json
  approved_ui\restaurant_pos.html
  public\api.php
  src\Services\PosService.php
  src\Services\OpsService.php
  src\Services\PosConflict.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V197.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V197 build 2026-11-17
  RELEASES.json                      "V197 build 2026-11-17", "for": ["RESTAURANT"]
  approved_ui\restaurant_pos.html    function offerDraft(
  public\api.php                     function pos_conflict(
  src\Services\PosService.php        private static function txRetry(
  src\Services\OpsService.php        'rev'      =>
  src\Services\PosConflict.php       final class PosConflict (NEW FILE)

  Every changed file also carries: V197 build 2026-11-17


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V197 POS rush safety"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on EVERY POS PC press Ctrl+F5 once (all counters must
run the new screen).


############################################################
 TESTS — cloud-style server (8 workers) AND shop-PC style (1)
############################################################

 Rush + race (17 of 17, run 3 times on cloud style, once shop style):
 - 8 counters x 12 bills at the same moment: 96 of 96 saved, every
   bill in DB, no number twice, items = screen, total = items, no
   stray open bill. Heavy: 16 counters x 20 = 320 of 320.
   (Before: 1 to 45 of 96 refused, 5-7 half-saved open bills.)
 - Payment sent twice at once / again after success: ONE sale.
 - Held bill closed on two counters: second refused, one sale.
 - Hold / KOT on a closed bill: refused, bill unchanged.
 - Two counters, one removes item, other (old screen) saves:
   refused, removed item stays removed.
 - Tablet adds while counter open: charge refused, bill reopens
   with tablet item, then charged in full.
 - Hold / KOT sent twice at once: one bill, item to kitchen once.
 - Tablet adds twice to a table: one bill.
 - Request without the new id (older screen): still works.

 POS screen (13 of 13): net cut after save + cashier presses again
 = one sale and "pehle hi band" message; reload -> restore (same
 bill no / id / items) -> charge = one sale; paid bill not offered
 again; "Nahi" discards; counter B "Bill badal gaya" shows fresh
 bill; tablet item reopens the bill; no JavaScript errors.

 Regression: V196 removed-item tests 17/17 + 6/6, POS 13/13 + 10/10,
 login 15/15, owner app 19/19 + 22/22, all 34 restaurant reports,
 restaurant pages same as V196, retail 113/113 + 149/149.

============================================================
 V195 — RESTAURANT POS BILLS + NEW LOGIN PAGE
 Wabwar Software House                     build 2026-11-17
============================================================

REQUIRES: V194 installed. Check: VERSION must say
"V194 build 2026-11-16" before you start, and
"V195 build 2026-11-17" after.

WHAT CHANGED — ONLY THIS:

 1. Restaurant POS — bill no longer goes missing.
    Cause found: two counters both showed bill 0001. When the
    second counter closed its bill, the server joined it to the
    OTHER counter's open bill with the same number (e.g. table
    R-01's KOT order) and closed that one. The walk-in bill
    vanished and the table bill got the wrong items.
    Fix: a bill number that is already used today is never
    re-used — the server gives the next free number and the POS
    shows that number. Hold / KOT bills keep closing by their own
    id, so they still close correctly.

 2. Duplicate button — refreshes from the server on EVERY click
    and lists ALL bills of the shift (old limit was 50), in bill
    order. When the shift runs over midnight, each day gets a
    heading (Sun 15 Nov / Mon 16 Nov ...).

 3. Table click — if that table has a bill on hold (from any
    counter or tablet), clicking the table opens that bill.
    Same rule as before for who can open whose bill: a cashier
    opens only own bills; owner/manager opens all. A cashier who
    clicks a table held by someone else sees:
      "Table T-05 ka bill #15 <name> ke paas khula hai — wohi ya
       manager khol sakta hai"
    and no new bill is started on that table.
    A free table starts a new bill as before.

 4. New login page (restaurant AND retail, same page).
    - No business name on the login page (shows SmartPOS).
    - Phone: form is right at the top, no big banner above it.
      Big inputs (16 px text, no iPhone zoom), Show password.
    - The username decides the business: whoever logs in gets
      their own business, even if another business was logged
      in on the same browser before. ("wrong username/password"
      in that case is fixed.)
    - Offline (shop PC) login: no change in how it works.

 Nothing else changes. No database change. Retail POS, reports,
 owner app, offline sync: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V195.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 9 files:

  VERSION
  RELEASES.json
  approved_ui\login.html
  approved_ui\restaurant_pos.html
  public\api.php
  public\router.php
  src\Auth.php
  src\Services\PageData.php
  src\Services\PosService.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V195.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V195 build 2026-11-17
  RELEASES.json                      "V195 build 2026-11-17", "for": ["RESTAURANT", "RETAIL"]
  approved_ui\login.html             pwShow
  approved_ui\restaurant_pos.html    dupListHtml
  public\api.php                     case 'pos-table-bill':
  public\router.php                  no brand on login
  src\Auth.php                       V195 — JO USER LOGIN KARE
  src\Services\PageData.php          public static function shiftBills(
  src\Services\PosService.php        SELECT id FROM sites WHERE id=? FOR UPDATE

  Every changed file also carries: V195 build 2026-11-17


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V195 POS bill fix, duplicate list, table hold, login page"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant and retail shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on each POS PC press Ctrl+F5 once so the new POS
screen loads.


############################################################
 TESTS (run on the test servers before packaging)
############################################################

 Before the fix the problems were reproduced: walk-in bill merged
 into another counter's table bill; new bill missing from
 Duplicate; 63 bills in shift but only 50 listed; login of a
 second business on the same browser refused.

 Login (34 of 34): cloud and offline; two businesses one after
 the other on the same browser; same username in two businesses;
 wrong password; disabled user; ?b= link; logout/login again;
 phone sizes 360 / 390 / 414 px — form visible without scrolling.

 POS server (13 of 13): no merge into another open bill; 4 counters
 x 5 bills with the same stale number = 20 separate bills and 20
 different numbers; hold / KOT close the same bill by id; no
 duplicate bill number today.

 POS screen (10 of 10): Duplicate shows bills made after the screen
 opened and bills from another counter; all 88 bills listed in
 order with day headings; table held on another counter opens;
 cashier gets the message for a table held by the owner; free table
 starts a new bill; no JavaScript errors.

 Regression: restaurant pages same as V194; owner app 19 of 19;
 retail suite 149 of 149.

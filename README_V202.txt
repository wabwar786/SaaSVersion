============================================================
 V202 — BILL NUMBER, DISCOUNT LIMIT, DUPLICATE COPY
 Wabwar Software House                     build 2026-11-17
============================================================

REQUIRES: V201 installed. Check: VERSION must say
"V201 build 2026-11-17" before you start, and
"V202 build 2026-11-17" after.

WHAT CHANGED (restaurant sale point only; online and offline):

 1. Bill number always comes from the server.
    Before: POS left open over midnight showed yesterday's next
    number (#0142); the first bill of the new day was saved as #0142,
    so today's numbering started at 0142.
    Now: every NEW bill (charge, hold, KOT) takes today's next number
    from the server. The first bill after midnight is #0001. The POS
    screen and the printed bill / KOT show the server's number.

 2. Cashier discount limit.
    Before: a cashier could give any discount (even 100%), with no
    password and no record.
    Now:
    - New setting in POS > System & Settings:
      "Cashier max discount % (is se zyada par manager password)".
      Default 10. Only Admin / Owner / Manager can change it.
    - Up to the limit: cashier applies it as before.
    - Above the limit: "Discount ki had" box asks for a manager
      password. Wrong password: discount not applied.
      The approval is saved in the Audit log (DISCOUNT_APPROVED).
    - Owner / Manager / Admin: no limit.
    - Allowed without password: the customer's own discount from the
      customer profile, and a discount already approved on that bill
      (e.g. another counter or the tablet adds items later).
    - The server checks it too, so an old POS screen cannot skip it.

 3. Duplicate reprint.
    Before: a reprint looked exactly like the original bill; nobody
    knew who reprinted it.
    Now: a bill printed from "Duplicate Bill" has
    "*** DUPLICATE COPY ***" at the top. Items, amounts and bill
    number are the same. Who reprinted which bill, and when, is saved
    in the Audit log (BILL_REPRINT). The normal bill print is
    unchanged.

 No database change. Reports, retail: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V202.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 6 files:

  VERSION
  RELEASES.json
  approved_ui\restaurant_pos.html
  public\api.php
  src\Services\PosService.php
  src\Services\SettingsService.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V202.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V202 build 2026-11-17
  RELEASES.json                      "V202 build 2026-11-17", "for": ["RESTAURANT"]
  approved_ui\restaurant_pos.html    function askManager(
  public\api.php                     *** DUPLICATE COPY ***
  src\Services\PosService.php        private static function discAllowed(
  src\Services\SettingsService.php   'max_discount'    => 10.0,

  Every changed file also carries: V202 build 2026-11-17


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V202 bill number from server, discount limit, duplicate copy"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on each POS PC press Ctrl+F5 once.
Settings > Version must show "POS V202 build 2026-11-17".

To change the limit: login as Owner/Manager on the POS >
System > "Cashier max discount %" > Save Tax Settings.


############################################################
 TESTS — cloud-style server (8 workers) and shop-PC style
############################################################

 New (21 server + 9 POS screen):
 - POS showing stale #0142: bill gets today's next number.
 - After midnight (no bills today): first bill #0001, next #0002;
   hold and KOT also take server numbers; no number used twice.
 - Cashier 10%: allowed. 100% / 50%: refused without password;
   wrong password refused; manager password: saved and logged.
 - Approved 50% bill charged later without password: works.
 - Tablet adds items to a bill with approved 50%: kept (50% of new
   amount).
 - Owner 100%: allowed. Customer profile 25%: allowed for cashier.
 - Limit changed to 20%: cashier 20% allowed.
 - Duplicate: "DUPLICATE COPY" on top, audit row saved, same bill
   line / items / total; normal print unchanged.
 - POS screen: 10% no password; 30% opens password box; wrong
   password keeps 10%; right password applies 30% and bill saves 30%;
   owner 50% no box; Settings shows the limit; no page errors.

 Rush: 8 x 12 and 16 x 20 bills, counters + QR + tablets together
 (cloud style and shop style): all pass.

 Regression: POS screen 13/13, removed item 17/17 + 6/6, V199 10/10,
 V200 20/20 + 6/6, V201 14/14 + 5/5, tablet 6/6, POS 13/13 +
 duplicate list, login 15/15, accounts & closing reports 26/26 (test
 updated: its 25% discount now uses the manager password), tax,
 stock, QR, business isolation 9/9, owner app 19/19 + 22/22,
 restaurant pages same as V201, retail 113/113 + retail POS screen all pass.

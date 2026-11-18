============================================================
 V205 — LOGIN LOCK, SAFE CLOSING, KITCHEN PRINTING,
        PUBLIC ORDER LIMITS
 Wabwar Software House                     build 2026-11-18
============================================================

REQUIRES: V204 installed. Check: VERSION must say
"V204 build 2026-11-18" before you start, and
"V205 build 2026-11-18" after.

WHAT CHANGED (restaurant only; online and offline; no database change):

 1. Login lock. 5 wrong passwords on one login in 10 minutes = that
    login is locked for 10 minutes. 30 wrong from one device = that
    device is locked for 10 minutes. Applies to the login page, the API
    and super-admin login. Every wrong try is in the Audit log
    (LOGIN_FAIL). Before: 30 wrong, then the right one still worked.
 2. Closing an account while bills are being charged. Closing and
    charging now wait for each other. Money goes only into the
    charging cashier's OWN open account. If it was just closed, the
    bill is not charged, the cart stays on screen, and the POS says
    "Account band — naya account kholein". Before: those bills went
    into ANOTHER cashier's open account.
 3. Kitchen slips are really printed. Tablet / QR KOTs, KOTs made when
    a bill is charged, and kitchen cancel slips used to sit in a queue
    that nothing printed. Now every counter POS checks every 10 seconds:
      - Shop PC (offline) + kitchen printer with an IP address:
        the server sends the slip straight to the printer (port 9100).
      - Cloud, or printer without an IP: the counter prints it.
      - One slip is printed by one counter only.
      - KOTs printed with the POS "Send to Kitchen" button are not
        printed again.
      - Slips older than 30 minutes are never printed (no old pile on
        the day you update).
      - If a slip cannot be printed, the POS top bar shows a red
        "Kitchen print failed n" button; click it to send them again.
 4. Customer app orders (no login): whole plates only, 1 to 99 per item
    (before: 5000 Biryani or 0.3 plate). At most 10 orders per device
    in 10 minutes. If the branch already has 30 orders waiting, the
    customer sees "Restaurant abhi bohat busy hai — order ke liye
    phone karein".
 5. QR orders: at most 3 waiting orders per table, plus the same
    30-per-branch limit. (No per-device limit here: all guests on the
    restaurant WiFi share one IP.)
 6. Users' email / phone / permissions and signup requests are shown
    only to users with the "Users" permission (before: every cashier).
    Without login: nothing.
 7. Without login: no business / super-admin counts in build-info.
    login-diagnostics, login-health and session-diagnostics open only
    for super admin, business admin, or on the shop PC itself
    (they showed whether the default admin password still works).

 Reports, retail: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V205.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 11 files:

  VERSION
  RELEASES.json
  approved_ui\restaurant_pos.html
  public\api.php
  public\login-submit.php
  public\login-diagnostics.php
  public\login-health.php
  public\session-diagnostics.php
  src\Services\PosService.php
  src\Services\PrinterService.php
  src\Services\OpsService.php

  (src\Services\LoginGuard.php is a NEW file — nothing to delete.)


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V205.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V205 build 2026-11-18
  RELEASES.json                      "V205 build 2026-11-18", "for": ["RESTAURANT"]
  approved_ui\restaurant_pos.html    function kitchenPoll(
  public\api.php                     function public_order_guard(
  public\login-submit.php            LoginGuard::locked(
  public\login-diagnostics.php       V205 — yeh diagnostics pehle bina login
  public\login-health.php            V205 — yeh diagnostics pehle bina login
  public\session-diagnostics.php     V205 — yeh diagnostics pehle bina login
  src\Services\PosService.php        private static function ownShiftOrStop(
  src\Services\PrinterService.php    public static function kitchenPoll(
  src\Services\OpsService.php        V205 — band karna aur bill charge karna ek qatar mein
  src\Services\LoginGuard.php        final class LoginGuard   (new file)

  Every changed file also carries: V205 build 2026-11-18


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V205 login lock, safe closing, kitchen printing, order limits"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on each POS PC press Ctrl+F5 once.
POS > System > Version must show "POS V205 build 2026-11-18".

TELL THE SHOPS:
  - Kitchen slips from tablets / QR and cancel slips will now print.
    Shop PC: give each kitchen printer its IP address (Printers page)
    so the server prints straight to it. Without an IP, the counter
    prints them.
  - A user locked after 5 wrong passwords waits 10 minutes.


############################################################
 TESTS
############################################################

 New, server (25):
  Login: 5 wrong -> right refused 10 min; logged; other user fine;
  works after the lock time; 30 wrong from one device -> device lock;
  super-admin same; login page form same.
  Closing race (8 workers, 4 runs, 6 bills each while closing): no
  bill in another cashier's account; closed slip = money in account.
  Kitchen: tablet KOT printed by the first counter only; marked
  printed; counter's own KOT not printed again; shop PC + printer IP
  -> real ESC/POS bytes received by a test printer; printer off ->
  FAILED + badge count; Retry re-queues; slips older than 30 min never
  printed.
  App: 5000 -> 99; 0.3 refused; 10 per device then refused; branch
  with 30 waiting -> "busy". QR: 3 per table then refused; another
  table still orders.
  Info: users / requests only for Users permission; nothing without
  login; build-info counts hidden; diagnostics closed on cloud.
 New, POS screen (5): V205 shown; counter prints a tablet KOT; printer
  off -> red badge "Kitchen print failed 1"; click -> sent again; no
  page errors.
 All API calls (3): cashier, waiter and no-login run all 287 actions;
  nothing works that should not.

 Regression (all pass): V204 27/27 + 7/7, V203 36/36 + 6/6, V202 21/21
  + 9/9, V199 10/10, V200 20/20 + 6/6, V201 14/14 + 5/5, removed item
  17/17 + 6/6, POS screen 13/13, tablet 6/6, rush 17/17 + 17/17 big,
  counters + QR + tablets 7/7 (1 and 8 workers), login 15/15, accounts
  & closing reports 26/26, owner app 19/19 + 22/22, business isolation
  9/9, POS 13/13, retail 113/113 + screen 36/36.

============================================================
 V204 — SALE POINT: THE SERVER GUARDS EVERY ACTION
 Wabwar Software House                     build 2026-11-18
============================================================

REQUIRES: V203 installed. Check: VERSION must say
"V203 build 2026-11-18" before you start, and
"V204 build 2026-11-18" after.

HOW THIS AUDIT WAS DONE
 All 287 server actions were called with a cashier login and with a
 waiter (tablet-only) login, then every action that worked was checked
 by hand. Everything found is fixed in this one version. That check is
 now a test that runs with every version.

WHAT CHANGED (restaurant only; online and offline; no database change):

 1. Every save checks permission (same as delete). Before: a cashier
    could set a menu price to Rs 1 and create promotions, staff, tables,
    printers, suppliers and riders through the API. A cashier can still
    add a customer from the POS (no discount) and record a shift expense.
    The "demo record" action follows the same rule.
 2. Reducing or removing a kitchen-sent item: the SERVER now asks for
    the manager password (or a manager is logged in). Before, only the
    screen asked.
 3. The void log is written only by the server (item, qty, reason,
    approved by, by whom). Cashiers cannot write or delete it.
 4. Cancelling a held bill is recorded in the Audit log
    (BILL_CANCELLED: bill, amount, items, reason).
 5. The slip printed before payment says
    "*** BILL — UNPAID (NOT A RECEIPT) ***". Once it is printed,
    cancelling that bill needs the manager password.
 6. One manager-password check with one lock everywhere (5 wrong in
    5 minutes = 5-minute lock): POS approvals, force delete, delete,
    bulk delete, void bill, cash clear.
 7. A bill of a CLOSED account can be voided only by Owner / Admin.
    Closing History has a new column "Void after close"; the closing
    slip itself stays as it was. Logged as VOID_AFTER_CLOSE.
 8. Bill number: the next number is taken from ALL of today's bills.
    Before: with 300+ bills in a day and an earlier-day held bill
    charged, every following bill failed ("Duplicate entry").
 9. FBR / KPRA access key and WhatsApp API token are never sent to the
    screen (shown as ********). Saving the page with ******** keeps the
    real value. The debug action (item-debug) is Owner / Admin only.
    The backup list is management only.
10. Waiter with only the Tablet permission can now SAVE orders (before:
    "Access denied", so shops gave waiters the full POS). The tablet
    sends items only; discount, charges, tax, charging and cancelling
    stay with the counter.
11. Cash clear ("Cash handed over — clear it now" when opening a new
    shift) now needs the manager password. Before, anyone could clear
    any closed account with any amount. Logged as CASH_CLEARED.
12. Money actions work only by POST (not by opening a link), and a new
    bill with no items is refused (empty open bills blocked shift close).

 Reports, retail: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V204.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 13 files:

  VERSION
  RELEASES.json
  approved_ui\restaurant_pos.html
  public\api.php
  public\ops_db.js
  public\router.php
  src\Services\PosService.php
  src\Services\ModuleBridge.php
  src\Services\DeleteService.php
  src\Services\PageData.php
  src\Services\OpsService.php
  src\Services\SettingsService.php
  src\Services\WhatsApp.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V204.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V204 build 2026-11-18
  RELEASES.json                      "V204 build 2026-11-18", "for": ["RESTAURANT"]
  approved_ui\restaurant_pos.html    BILL — UNPAID (NOT A RECEIPT)
  public\api.php                     function pos_items_only(
  public\ops_db.js                   Void after close
  public\router.php                  ops_db.js?b=v204
  src\Services\PosService.php        private static function voidApprove(
  src\Services\ModuleBridge.php      public static function maySave(
  src\Services\DeleteService.php     public static function permOf(
  src\Services\PageData.php          AAJ KE SAARE BILL
  src\Services\OpsService.php        AS void_after
  src\Services\SettingsService.php   if ($k === 'access_key' && $v === '********') continue;
  src\Services\WhatsApp.php          public static function publicSettings(

  Every changed file also carries: V204 build 2026-11-18


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V204 sale point: server guards every action"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on each POS PC press Ctrl+F5 once.
POS > System > Version must show "POS V204 build 2026-11-18".

TELL THE SHOPS (two workflow changes):
  - Opening a new shift when the previous account's cash is not yet
    cleared: the manager types the password to confirm the handover.
  - Waiters can now work with only the "Tablet" permission. You may
    remove the POS permission from waiter users.


############################################################
 TESTS
############################################################

 New, server (27): cashier cannot change menu price; cannot save
  promotions / staff / tables / printers / suppliers / riders /
  wastage; can still add a customer (0%) and a shift expense; owner can
  save; demo-record follows the same rule; API removal of kitchen-sent
  item and Naan 2->1 without password refused, bill unchanged; wrong
  password refused; with password charged and 2 server void logs
  written (approved by, reason); cashier cannot write / delete void
  log; logged-in manager removes without password, log shows manager;
  cancel recorded with bill / amount / items; printed slip -> cancel
  needs manager, log says slip was printed; 5 wrong passwords on force
  delete -> same lock; cash clear without password refused, with
  password cleared and logged; Branch Manager cannot void a closed
  account's bill, Owner can, Closing History shows -Rs 400, logged;
  1000+ bills today + earlier-day bill -> next bills work, numbers
  unique; FBR key / WhatsApp token never on screen (cashier or owner),
  saving the mask keeps the real values; debug closed for cashier;
  tablet-only waiter saves an order, discount / charges ignored; waiter
  cannot charge or cancel.
 New, POS screen (7): V204 shown; removing a kitchen-sent line asks the
  manager password; after save the line is gone and the server wrote the
  void log; Void Logs screen shows it; pre-payment slip says UNPAID;
  paid receipt does not; no page errors.
 New, all API calls (2): cashier and waiter logins call all 287 actions;
  no action works that should not (cashier 62, waiter 49 allowed).

 Regression (all pass): V203 36/36 + 6/6, V202 21/21 + 9/9, V199 10/10,
  V200 20/20 + 6/6, V201 14/14 + 5/5, removed item 17/17 + 6/6, POS
  screen 13/13, tablet 6/6, rush 17/17 (8 workers) and 17/17 big,
  counters + QR + tablets 7/7 (1 and 8 workers), login 15/15, accounts
  & closing reports 26/26 (its kitchen-void step now sends the manager
  password), owner app 19/19 + 22/22, business isolation 9/9, POS
  13/13, retail 113/113 + screen 36/36.

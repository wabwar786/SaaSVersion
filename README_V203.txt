============================================================
 V203 — SALE POINT: THE SERVER CHECKS EVERY FIELD
 Wabwar Software House                     build 2026-11-18
============================================================

REQUIRES: V202 installed. Check: VERSION must say
"V202 build 2026-11-17" before you start, and
"V203 build 2026-11-18" after.

WHY THIS VERSION
 This time every field the POS / tablet / QR sends to the server was
 listed and tested with wrong values (36 cases). Before V203 the server
 believed several of them. Now the server decides.

WHAT CHANGED (restaurant only; online and offline; no database change):

 1. Item price and name come from the menu.
    Accepted price: the menu price, a variant price of that item, or the
    price already saved on that bill line (if the menu price changed
    later). Anything else is replaced by the menu / variant price and
    written in the Audit log (PRICE_CORRECTED). The POS then refreshes
    its menu so the slip and the bill match.
    A line name that does not start with the menu name is printed with
    the menu name (kitchen and bill).
 2. Lines with qty 0 or minus are dropped (before: Biryani x-1 made
    the bill smaller).
 3. Service charge and delivery charge cannot be minus.
 4. Tax rate comes from Settings: card / Raast / Easypaisa / JazzCash /
    Bank / Online = card rate, everything else = cash rate. A tax amount
    sent by the screen is not used.
 5. Discount % is saved only when it matches the discount amount.
 6. An approved discount works by %: if the bill becomes smaller the
    discount becomes smaller with it (before: Rs 800 approved on 4 items
    stayed on 2 items -> free bill).
 7. Customer "Discount %" can be set or changed only by Owner / Manager /
    Admin. The cashier's New Customer box no longer shows the field; a
    cashier editing a customer keeps the manager's discount.
 8. Manager password: 5 wrong tries in 5 minutes = that user is locked
    for 5 minutes. Every wrong try is in the Audit log (MGR_PW_FAIL).
    Covers: discount approval, void/reduce approval, cancel bill.
    Normal bills still work while locked.
 9. A bill goes only into the cashier's OWN open account (shift).
10. Any server print of a CHARGED bill is marked "*** DUPLICATE COPY ***"
    and logged (BILL_REPRINT), also from a plain link. The first receipt
    is printed by the POS at payment. A bill not yet charged prints
    without the mark.
11. A held bill from an earlier day, charged today, moves to today's
    date and gets today's next number. The old number is in the Audit
    log (BILL_RENUMBERED). Shift closing and daily sales now match.
12. QR orders: whole plates only (2.7 -> 2), at most 99 per item;
    weight items keep decimals.

 Reports, retail: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V203.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 6 files:

  VERSION
  RELEASES.json
  approved_ui\restaurant_pos.html
  public\api.php
  src\Services\PosService.php
  src\Services\ModuleBridge.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V203.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V203 build 2026-11-18
  RELEASES.json                      "V203 build 2026-11-18", "for": ["RESTAURANT"]
  approved_ui\restaurant_pos.html    function syncPrices(
  public\api.php                     function pos_disc_pw(
  src\Services\PosService.php        public static function managerByPassword(
  src\Services\ModuleBridge.php      Customer discount sirf manager / owner set kar sakta hai.

  Every changed file also carries: V203 build 2026-11-18


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V203 sale point: server checks every field"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: on each POS PC press Ctrl+F5 once.
POS > System > Version must show "POS V203 build 2026-11-18".

Check Settings once: POS > System > Cash tax % and Card tax % are now
used for every bill.


############################################################
 TESTS
############################################################

 New, server (36):
  price 1 / 9999 -> menu price; variant price kept; wrong variant
  price -> variant price; "(Regular)" kept; menu price raised while
  bill held (old line 400, new line 450); renamed line -> menu name;
  qty -1 / 0 dropped; other business's item refused; service -300 and
  delivery -300 -> 0; tax -200 -> Settings tax; card bill -> card tax;
  hold 99% -> cash tax; fake 90% then tablet add -> no discount;
  approved 50% then smaller bill keeping amount -> refused, with 50%
  -> charged; cashier 90% customer refused, 0% saved, cashier edit
  keeps manager's 15%; fake "approved by" field ignored; 5 wrong
  passwords -> lock (also via bill discount password), other cashier
  not locked, locked cashier can still charge; other cashier's shift id
  -> own shift; charged bill plain link -> DUPLICATE + log; open bill ->
  no mark; earlier-day held bill -> today's date and number + log;
  shift money = today's sales; QR 2.7 -> 2, 500 -> 99, 0.4 refused;
  cash received 10 -> full payment recorded.
 New, POS screen (6): V203 shown; cashier customer box has no discount;
  screen price changed to 1 -> saved 400 and screen back to 400;
  5 wrong passwords -> lock message, discount not applied; owner box
  has discount; no page errors.

 Regression (all pass): V202 21/21 (one check updated to rule 10) +
  9/9, V199 10/10, V200 20/20 + 6/6, V201 14/14 + 5/5, removed item
  17/17 + 6/6, POS screen 13/13, tablet 6/6, rush 17/17 (8 workers),
  17/17 big, counters + QR + tablets 7/7 (1 and 8 workers), login
  15/15, accounts & closing reports 26/26 (profit & loss check now
  includes tax from Settings), owner app 19/19 + 22/22, business
  isolation 9/9, POS 13/13, retail 113/113 + screen 36/36.

============================================================
 V209 — RETAIL: ITEM CREATION + DEPARTMENT DISCOUNT
 Wabwar Software House                     build 2026-11-25
============================================================

REQUIRES: V208 installed. Check: VERSION must say
"V208 build 2026-11-22" before you start, and
"V209 build 2026-11-25" after.

This update is for RETAIL businesses only. The update notice is shown
to retail businesses only. Restaurant screens and behaviour do not
change. Restaurant offline shops do not need this update.
No database change (no new table, no new column).

WHAT IS NEW (retail):

 1. Item Creation (replaces Product Catalog — same menu place, same
    permission "products", same page products.html)
    Left: entry form. Right: item grid.
    - Item code / Barcode is ONE field. A new item gets the next auto
      number (100001, 100002 ...). Scan the item's own barcode to
      replace it. Auto numbers use only codes of up to 6 digits, so a
      scanned 13-digit barcode never moves the counter. Two counters
      saving at the same moment get different numbers.
    - Fields: Item name, Department (+), Category (+, by department),
      Company (type to pick or add), Cost price, Sale price (margin
      shown), Unit, Packing size, Box code (carton barcode — scanning it
      at the POS sells the full box), Opening qty (new item only).
    - Enter moves to the next box; Enter on the last box saves. After
      save the form clears, keeps the last department/category and the
      cursor goes back to the code.
    - A code or box code already used is shown red and cannot be saved.
      Enter on an existing code opens that item for edit.
    - Same item name: asks before saving. Sale price below cost: asks.
    - Edit any item any time (double-click a row or Edit). Stock is not
      changed by edit (use Purchase / Stock Count). Tax, MRP, wholesale
      price and min stock set elsewhere are kept as they are.
    - Grid: S.No, Item code / Barcode, Item name, Department, Category,
      Company, Unit, Packing, Box code, Cost, Sale price, Stock.
      Filters: code (also finds box code), name (F3), department,
      category. 100 items per page.
    - Keys: F2 new, F3 search, Enter next box, Ctrl+S save, Esc clear.
    - "Import from Excel" button is still there.

 2. Department discount (button on Item Creation)
    - Department, Category (All or one), Discount % (1–90), Name (shown
      on the bill), From and To dates (whole days).
    - Not allowed: To before From, dates already over, overlapping
      dates on the same department/category.
    - List shows Running / Scheduled / Ended, with Stop.
    - Only a manager or the owner can save or stop a discount.
    - Sale prices are NOT changed. The POS (Retail price level only)
      takes the % off the line; the line shows the discount and its
      name; the receipt and the saved bill carry it. The server works
      it out again on every bill, so the screen cannot skip it.
    - The cashier's own discount limit applies only to the cashier's
      extra discount, not to the department discount.
    - Kept per branch in Settings (retail / dept_discounts), which shop
      PCs already sync. POS counters pick up changes within 5 minutes
      (and at once when the bill total is checked before payment).

 3. Left menu close / open (all retail pages except POS)
    Small round button at the edge of the menu. Each computer remembers
    the choice.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V209.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 9 files:

  VERSION
  RELEASES.json
  src\Services\RetailPos.php
  src\Services\RetailCatalog.php
  public\api.php
  public\router.php
  approved_ui\retail\products.html
  approved_ui\retail\pos.html
  approved_ui\retail\shell.js

  New file (nothing to delete):
  src\Services\RetailItems.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V209.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                                   Search for
  -------------------------------------  ----------------------------------
  VERSION                                V209 build 2026-11-25
  RELEASES.json                          "V209 build 2026-11-25", "for": ["RETAIL"]
  src\Services\RetailItems.php           public static function saveDiscount
  src\Services\RetailPos.php             RetailItems::ruleFor
  src\Services\RetailCatalog.php         department_id,category_id
  public\api.php                         case 'ritems-save'
  public\router.php                      ?b=r10
  approved_ui\retail\products.html       <h1>Item Creation</h1>
  approved_ui\retail\pos.html            function promoOf
  approved_ui\retail\shell.js            rtl_nav_collapsed

  Every changed file also carries: V209 build 2026-11-25


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V209 retail Item Creation + department discount"
      git push
  Railway deploys by itself.

OFFLINE (retail shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: press Ctrl+F5 once in the browser.
Item Creation shows "Item Creation · V209 build 2026-11-25" at the
bottom right.


############################################################
 TESTS
############################################################

 New — Item Creation + discount API 92/92:
  - auto code 100001 -> 100002; 13-digit and 7-digit codes do not move
    it; 5 counters saving at once: 5 different codes, nothing half-saved;
  - opening qty = stock + OPENING stock move; edit never changes stock;
  - box code + packing: carton row (factor 6, price 1,080), POS finds
    the item by box code and by item code;
  - refused: duplicate code, code = another item's box code, box code =
    another item's code or its own code, no name / price / department,
    space in code, negative opening, another business's department;
  - edit: code changed in place (old code free again, POS no longer finds
    it), tax / MRP / wholesale / min stock kept, box code removed and
    added back;
  - grid filters: code prefix, box code, name, 2-word name, department,
    category, "%" typed is literal;
  - discount: 0% / 95% / To before From / past dates / overlap refused;
    saved in Settings (no new table); POS quote 2 x 180 -> 72 off,
    total 387; wholesale level no discount; cashier with 10% limit still
    gets the 20% department discount but not 20% of his own; sale total
    288, bill line discount 72, stock -2, catalog price unchanged; old
    screen total ignored by server; Stop -> no discount; category rule
    only on that category; cashier cannot set a discount;
  - cashier without "products" cannot open Item Creation; no CSRF / GET
    refused; restaurant business refused (403) on every new action;
    anonymous refused.
 New — screens 37/37: version, focus on code with auto number, Enter
  through every box saves, form clears keeping department, green saved
  row with -15%, red duplicate, Enter opens existing item, Ctrl+S
  update, F3, filters, double-click edit, Esc, same-name and below-cost
  questions, + department, + category, discount modal (overlap shown,
  preview, save, Esc), menu close / open remembered on another page,
  POS line shows Rs 18 discount and the name, total Rs 102, F10
  wholesale removes it, back to retail restores it, no page errors.

 Regression (all pass): retail 113/113 + screens 149/149, Purchase
  Entry 50/51 + 22/23; restaurant V207 sync 55/55 + 7/8, V205 25/25 +
  4/5, V204 27/27 + 6/7, V203 36/36 + 5/6, V202 21/21 + 9/9, V201 14/14 +
  5/5, V200 20/20 + 6/6, V199 10/10, removed item 17/17, POS screen
  13/13, tablet 6/6, login 15/15, business isolation 9/9, accounts &
  closing 26/26, permissions 3/3, owner app 22/22. Each "1 short" above is the same
  line: it expects build V208 on screen and now reads V209. Module menu check OK, retail
  wiring check OK.

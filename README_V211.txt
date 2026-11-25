============================================================
 V211 — RETAIL: ITEM CREATION — GRID FILTERS FROM THE FORM
 Wabwar Software House                     build 2026-11-25
============================================================

REQUIRES: V210 installed. VERSION must say "V210 build 2026-11-25"
before you start, and "V211 build 2026-11-25" after.

RETAIL only. Restaurant screens and behaviour do not change.
Restaurant offline shops do not need this update. No database change.

WHAT IS NEW (Item Creation):
 1. Picking Department, Category or Company in the left form filters
    the item grid by them. The grid's Department / Category boxes
    follow the form; the company is shown under the grid
    ("Company: GUARD"). Opening an item for edit does not change the
    grid filter.
 2. Item name search filters only when Enter is pressed — typing alone
    does not filter. (Code / barcode search works as before.)


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################
  Where: your local PC, project folder.
  Copy DELETE_OLD_FILES_V211.bat into the project folder, double-click,
  press Y. It deletes ONLY these 4 files:

  VERSION
  RELEASES.json
  src\Services\RetailItems.php
  approved_ui\retail\products.html


############################################################
 STEP 2 — EXTRACT SaaSVersion_V211.zip INTO THE PROJECT ROOT
############################################################
  Where: your local PC, same project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################
  File                                  Search for
  ------------------------------------  -----------------------------------
  VERSION                               V211 build 2026-11-25
  RELEASES.json                         "V211 build 2026-11-25", "for": ["RETAIL"]
  src\Services\RetailItems.php          'department_id', 'category_id', 'brand_id'
  approved_ui\retail\products.html      function followLeft

  Every changed file also carries: V211 build 2026-11-25


############################################################
 STEP 4 — DEPLOY
############################################################
CLOUD (Railway) — your local PC, project folder, CMD:
    git add -A
    git commit -m "V211 retail Item Creation grid filters"
    git push
OFFLINE (retail shops) — shop PC: GET_UPDATE.bat, then INSTALL_UPDATE.bat.
After deploy press Ctrl+F5 once. Item Creation shows
"Item Creation · V211 build 2026-11-25" at the bottom right.


############################################################
 TESTS
############################################################
 Item Creation API 96/96 (new: company filter) and screens 44/44 (new:
  left Department -> grid shows only that department, left Category ->
  only that category, left Company -> only that company + "Company:"
  shown, typing a name does not filter, Enter filters).
 Regression (all pass): retail 113/113 + screens 149/149; restaurant
  V207 sync 55/55, V205 25/25, removed item 17/17, POS screen 13/13,
  tablet 6/6, login 15/15, business isolation 9/9, permissions 3/3,
  owner app 22/22.

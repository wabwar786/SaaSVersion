============================================================
 V210 — RETAIL: ITEM CREATION — COMPANY DROP-DOWN + ADD
 Wabwar Software House                     build 2026-11-25
============================================================

REQUIRES: V209 installed. VERSION must say "V209 build 2026-11-25"
before you start, and "V210 build 2026-11-25" after.

RETAIL only. Restaurant screens and behaviour do not change.
Restaurant offline shops do not need this update. No database change.

WHAT IS NEW:
 Item Creation > Company
  - Drop-down list of all existing companies (A-Z), "— None —" first.
  - "+" next to it adds a new company (popup, Enter saves); the new
    company is selected straight away. Same name typed again = the
    existing company is used (no duplicates).
  - On edit, the item's company is shown selected.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################
  Where: your local PC, project folder.
  Copy DELETE_OLD_FILES_V210.bat into the project folder, double-click,
  press Y. It deletes ONLY these 4 files:

  VERSION
  RELEASES.json
  src\Services\RetailItems.php
  approved_ui\retail\products.html


############################################################
 STEP 2 — EXTRACT SaaSVersion_V210.zip INTO THE PROJECT ROOT
############################################################
  Where: your local PC, same project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################
  File                                  Search for
  ------------------------------------  -----------------------------------
  VERSION                               V210 build 2026-11-25
  RELEASES.json                         "V210 build 2026-11-25", "for": ["RETAIL"]
  src\Services\RetailItems.php          if ($kind === 'company')
  approved_ui\retail\products.html      id="addCo"

  Every changed file also carries: V210 build 2026-11-25


############################################################
 STEP 4 — DEPLOY
############################################################
CLOUD (Railway) — your local PC, project folder, CMD:
    git add -A
    git commit -m "V210 retail Item Creation company drop-down"
    git push
OFFLINE (retail shops) — shop PC: GET_UPDATE.bat, then INSTALL_UPDATE.bat.
After deploy press Ctrl+F5 once. Item Creation shows
"Item Creation · V210 build 2026-11-25" at the bottom right.


############################################################
 TESTS
############################################################
 Item Creation API 95/95 (new: add company upper-cased, same name
  returns the existing one, boot lists it) and screens 40/40 (new:
  Company is a drop-down with existing companies, edit shows the item's
  company, + adds and selects a new company).
 Regression (all pass): retail 113/113 + screens 149/149, Purchase
  Entry 50/51 (the 1 line expects build V208); restaurant V207 sync
  55/55, V205 25/25 + 4/5 (build-number line), removed item 17/17,
  POS screen 13/13, tablet 6/6, login 15/15, business isolation 9/9,
  permissions 3/3, owner app 22/22.

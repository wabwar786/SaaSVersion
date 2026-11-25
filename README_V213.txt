============================================================
 V213 — RETAIL: LEFT MENU SHOWS ONLY THE SCREENS IN USE
 Wabwar Software House                     build 2026-11-25
============================================================

REQUIRES: V212 installed. VERSION must say "V212 build 2026-11-25"
before you start, and "V213 build 2026-11-25" after.

RETAIL only. The restaurant menu is a different file and is not
touched. public\router.php (shared) changes only in the retail
cache number (r11 -> r12), so every retail PC loads the new menu.
No database change.

WHAT CHANGED
 The retail left menu now shows 32 screens (Dashboard + 31).
 These 16 are HIDDEN from the menu for now. Nothing is deleted: the
 pages, the data and the permissions stay as they are.
   Purchasing, Goods Receipt (GRN), Brands, Units & Pack Sizes,
   Price Management, Discounts / Promotions, WhatsApp / Notifications,
   Accounting / Cash, Weighing Scale Items, Batch & Expiry,
   Loyalty / Membership, Expenses, Tax / Digital Invoice,
   Staff / Roles, Multi-Branch, User Activity Log.
 Each one is shown again when its screen is finished (one word
 removed from the HIDDEN list in shell.js).
 The "Accounting / Cash" permission still controls Payment Slips and
 Supplier Payment in Users & Access.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################
  Where: your local PC, project folder.
  Copy DELETE_OLD_FILES_V213.bat into the project folder, double-click,
  press Y. It deletes ONLY these 4 files:

  VERSION
  RELEASES.json
  public\router.php
  approved_ui\retail\shell.js


############################################################
 STEP 2 — EXTRACT SaaSVersion_V213.zip INTO THE PROJECT ROOT
############################################################
  Where: your local PC, same project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################
  File                            Search for
  ------------------------------  ---------------------------------------
  VERSION                         V213 build 2026-11-25
  RELEASES.json                   "V213 build 2026-11-25", "for": ["RETAIL"]
  public\router.php               ?b=r12
  approved_ui\retail\shell.js     var HIDDEN = [

  Every file also carries: V213 build 2026-11-25


############################################################
 STEP 4 — DEPLOY
############################################################
CLOUD (Railway) — your local PC, project folder, CMD:
    git add -A
    git commit -m "V213 retail menu: only screens in use"
    git push
OFFLINE (retail shops) — shop PC: GET_UPDATE.bat, then INSTALL_UPDATE.bat.
After deploy press Ctrl+F5 once.


############################################################
 TESTS
############################################################
 New — menu 7/7:
  - all 32 screens in use are in the menu;
  - none of the 16 hidden items is in the menu;
  - a hidden page still opens by its address (nothing deleted);
  - new menu file is served (r12);
  - restaurant pages do not load the retail menu;
  - no page errors.
 Regression (all pass):
  - Retail: aud 113/113, screens 149/149, Item Creation 96/96 +
    44/44, Supplier payables 71/71 + 35/35.
  - Restaurant: POS screen 13/13, tablet 6/6, business isolation 9/9.

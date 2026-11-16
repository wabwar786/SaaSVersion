============================================================
 V194 — OWNER MOBILE APP: YESTERDAY'S SALE
 Wabwar Software House                     build 2026-11-16
============================================================

REQUIRES: V193 installed. Check: VERSION must say
"V193 build 2026-11-13" before you start, and
"V194 build 2026-11-16" after.

WHAT CHANGED — ONLY THIS:
 The owner mobile app (owner_mobile.html) has a new switch at the
 top of the Today tab: Today | Yesterday. Yesterday shows the whole
 of yesterday.

 Nothing else changes:
 - Today shows exactly what it showed before (tested: the server's
   Today data from V193 and V194 is byte-for-byte the same).
 - Sales, Stock and Account tabs: no change.
 - POS, reports, retail, offline sync: no change.
 - No database change. The app only reads data; it writes nothing.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V194.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 5 files:

  VERSION
  RELEASES.json
  public\api.php
  src\Services\OwnerMobile.php
  approved_ui\owner_mobile.html


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V194_OWNER_APP.zip into
  the same project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V194 build 2026-11-16
  RELEASES.json                      "V194 build 2026-11-16", "for": ["RESTAURANT"]
  public\api.php                     case 'owner-mobile-day':
  src\Services\OwnerMobile.php       public static function yesterday()
  approved_ui\owner_mobile.html      data-day="yesterday"


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V194 owner app yesterday sale"
      git push
  Railway deploys by itself. No migration.

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.
  Only restaurant shops see this update notice (RELEASES.json).

The page is never cached, so phones show the switch on the next open.


############################################################
 HOW YESTERDAY IS COUNTED
############################################################

 - A bill belongs to the day it was CLOSED — the same rule the
   reports and the dashboard use. A bill opened at 11:50 pm and
   closed at 12:20 am counts in the next day.
 - Yesterday is compared with the whole day before it.
 - Shows: total, bills, average bill, guests, discount, food cost,
   expenses, what is left, cash / card / online, busiest hours,
   top sellers, sales by cashier.
 - "Running / In kitchen / Tables" chips are for Today only.
 - Yesterday is fetched only when the owner taps it (one small
   request), so opening the app is not slower.
 - Same access as the app: owner or manager, and only when the owner
   app is switched on for that business.


############################################################
 TESTS (real POS bills on the restaurant test business)
############################################################

 Server (19 of 19), plus cashier check:
 - Yesterday total = Sales summary report for the same day
   (4 bills, Rs 10,040).
 - Bill closed after midnight counted on the right day, both ways.
 - Cash + card + online = total; expenses, hours, top sellers.
 - Day before compared as a full day.
 - Empty day: zeros, no error.
 - Refused: owner app off, not logged in, cashier, any day other
   than yesterday.
 - Today data: same as V193, byte for byte.

 Phone screen, 390 px and 360 px (22 of 22):
 - Switch shows; Yesterday loads; back to Today is unchanged.
 - Yesterday is loaded from the server once only.
 - Sales, Stock, Account tabs still open; no JavaScript errors.
 - Switch fits the screen and is finger-size (44 px).

 All restaurant pages opened: same result as V193.

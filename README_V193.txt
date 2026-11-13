============================================================
 V193 — RETAIL SALE POINT: AUTO PRINT, COUNTERS, DUPLICATE
        BY DATE, LARGE DATABASE SPEED, OFFLINE DB REPAIR
 Wabwar Software House                     build 2026-11-13
============================================================

REQUIRES: V192 installed. Check: VERSION must say
"V192 build 2026-11-13" before you start, and
"V193 build 2026-11-13" after.

RESTAURANT: no restaurant screen changes. Shared pieces:
 - src\Auth.php: business type is read on its own (section 6).
 - tools\start_offline.ps1 and scripts\check_local_schema.php:
   offline database check (section 6). Helps any business type.
 - public\api.php / public\router.php: retail-only blocks.
 All restaurant pages were opened before and after: same result.

DATABASE: one new index (scripts\migrate_retail_v193.php). It runs
by itself (cloud deploy, UPDATE, and START_OFFLINE). Nothing to
run by hand.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V193.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY the files below.
     Files that are new in V193 show "not found".

  VERSION
  RELEASES.json
  public\api.php
  public\router.php
  src\Auth.php
  src\Services\RetailPos.php
  src\Services\RetailShift.php
  scripts\check_local_schema.php
  scripts\migration_order.php
  scripts\migrate_retail_v193.php                (new)
  tools\start_offline.ps1
  approved_ui\retail\pos.html
  approved_ui\retail\retail_api.js


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V193_RETAIL.zip into
  the same project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                                 Search for
  -----------------------------------  ---------------------------------------
  VERSION                              V193 build 2026-11-13
  RELEASES.json                        "V193 build 2026-11-13", "for": ["RETAIL"]
  public\api.php                       case 'rpos-hold-claim':
  public\router.php                    ?b=r9
  src\Auth.php                         tenantRegion:
  src\Services\RetailPos.php           public static function claimHold
  src\Services\RetailShift.php         private static function dbNow
  scripts\check_local_schema.php       SCHEMA_STALE
  scripts\migration_order.php          migrate_retail_v193
  scripts\migrate_retail_v193.php      ix_rs_cust_sold
  tools\start_offline.ps1              3a2) V193
  approved_ui\retail\pos.html          S.claimHold(id)
  approved_ui\retail\retail_api.js     S.claimHold = function


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway) — deploy the cloud FIRST.
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V193 retail auto print, counters, duplicate by date"
      git push
  Railway deploys by itself and builds the new index in the
  background (about 12 s per 900,000 bills).

OFFLINE SHOPS (retail)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.
  On the next START_OFFLINE the window shows once:
  "Updating database tables for this version (one time only)..."

Browser cache: retail files now load with ?b=r9.


############################################################
 1 · RECEIPT PRINTS WHEN THE BILL CLOSES
############################################################

 - Confirm & print, Enter and Ctrl+Enter: the receipt prints at
   once (before: it only showed on screen until Print was pressed).
 - Duplicate bill (Ctrl+D) also prints at once.
 - After printing, the keyboard and barcode scanner go back to the
   POS. (Found in testing: without this, the next scan, Enter and
   Ctrl+D went into the hidden print frame.)
 - The Print button on the receipt still reprints.
 - The browser shows its print window. To print with no window,
   Chrome/Edge must be started with --kiosk-printing (not changed
   in this build).


############################################################
 2 · DUPLICATE BILL
############################################################

 - Enter in the Duplicate list no longer closes the receipt the
   moment it opens (before: nothing showed and nothing printed,
   but the copy was still counted).
 - New date box: pick a date to list that day's bills (latest 60).
   Type a bill number or customer name to narrow it.
 - Typing part of a number works: "10" finds INV-10..., before
   only the full number did.
 - Customer name search uses the customers list (walk-in bills
   have no customer: find them by number or date).


############################################################
 3 · COUNTERS (2, 3 OR MORE)
############################################################

 - Held bills belong to the counter that held them. F5, Alt+1..9
   and the held chips show only this counter's bills (before:
   Counter 2 could open Counter 1's held bill).
 - A held bill can be taken back by one counter only. If two
   counters press it at the same moment, the second gets
   "This bill was already recalled".
 - Bill numbers: counters now take the next number one at a time.
   Before, on a large database with 6 counters billing at once,
   4 of 60 bills failed with "database problem". Now 240 of 240,
   no repeated number, no gaps.
 - Each computer's counter is set from the counter chip on the POS
   top bar (Counter Management lists the names).


############################################################
 4 · SHIFT CASH
############################################################

 - A bill saved in the same second as the shift view or shift
   close was left out of every shift's cash. Times now come from
   the database (to the microsecond).


############################################################
 5 · LARGE DATABASE (tested at 11.75 GB)
############################################################

 Test database: 900,000 bills, 9,000,000 bill lines, 8,000,000
 stock moves, 60,000 items, 3,000 customers, 3 years of dates.
 MariaDB with 256 MB memory (same as the offline package), also
 tested right after a MariaDB restart (nothing in memory):

   POS open 7 ms | barcode scan 3 ms | name search 7 ms
   10-line bill save 23 ms | 40-line bill save 66 ms
   duplicate list 4 ms | by bill number 3 ms | by old date 3 ms
   reprint of a 2023 bill 5 ms | shift summary 4 ms
   dashboard 10 ms | products page 37 ms | stock card 6 ms

 Found and fixed: customer name search in Duplicate bill read the
 whole bills table — 6 to 11 s for a name with no bills and 4 s
 for a customer with 20,000 bills, with the counter frozen. Now
 11 ms and 52 ms (new index ix_rs_cust_sold).


############################################################
 6 · OFFLINE: DATABASE MADE BY AN OLDER VERSION
############################################################

 What happened on a PC that already had the restaurant install:
 retail used the restaurant's database. All tables existed, so
 retail's start-up check passed and the newer columns were never
 added. Result: after login the restaurant design with supermarket
 data, and every bill failed with "database problem".

 Now:
 - START_OFFLINE checks the columns too. If one is missing, or this
   is a new version, it runs the full migration list once (the same
   list the cloud and UPDATE use). Existing data is not touched.
   storage\schema_version.txt records the done version, so the next
   start is instant.
 - The business type is read from its own column, so a missing
   column can no longer switch a supermarket to the restaurant
   design. A failure is written to the error log.
 Tested on the sealed offline package with both columns removed:
 repaired in 2.5 s, data intact, retail login, bill and print OK.


############################################################
 TESTS (fresh test business each run, MariaDB, real browser)
############################################################

 - Sale point audit, 149 scenarios: 149 pass (were 145 on V192).
 - 40-item bill with quantities 1-5: average 31-69 ms per scan,
   no slow-down as the bill grows; hold and recall in under
   0.1 s with identical lines; saved in 0.2-0.4 s; prints;
   duplicate and old-date duplicate print. 25 of 25, on the normal
   and on the 11.75 GB database.
 - 4 and 8 counters at the same time (separate browsers and
   cashiers): bills, receipts, held bills, shifts and stock never
   mix. 13 of 13 each.
 - 11.75 GB speed: 24 of 24.
 - Restaurant: all pages opened, same result as V192.

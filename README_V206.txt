============================================================
 V206 — SALE POINT FAST AFTER YEARS OF DATA,
        NIGHT SALES ON THE RIGHT DAY
 Wabwar Software House                     build 2026-11-18
============================================================

REQUIRES: V205 installed. Check: VERSION must say
"V205 build 2026-11-18" before you start, and
"V206 build 2026-11-18" after.

WHAT CHANGED (restaurant sale point; online and offline):

 1. Database clock = app clock (Pakistan time).
    The app runs on Pakistan time; the cloud database usually runs on
    UTC and the connection never set a time zone. Between midnight and
    5 AM: the account opened on YESTERDAY's date, the expense went to
    YESTERDAY, the bill to TODAY; today's Closing History did not show
    the account and today's Sales Summary was empty. Now every database
    connection uses the app's time, so all of them get the same day.
    On the shop PC both were already the same — nothing changes there.
    Old cloud rows keep the times they were saved with.

 2. Sale point stays fast after years of data.
    Measured on a shop-PC style server with 2 years of history
    (300,000 bills), 3 counters billing together:
                                       V205          V206
      One bill                         425 ms        71 ms
      Worst wait while owner           3,700 ms      213 ms
        dashboard / closing open
      Account preview / closing        3,035 ms      35 ms
      POS open / Duplicate list        751/769 ms    28/21 ms
      Owner dashboard                  2,170 ms      77 ms
      Closing History                  3,632 ms      9 ms
      Tablet tables                    229 ms        3 ms
      Kitchen print check              142 ms        9 ms
    How:
      - 4 database indexes (orders by branch + closing time, payments by
        branch + time, open bills by branch, kitchen slips by bill).
        They are added AUTOMATICALLY by the update (new migration
        "migrate_speed_v206"). Cloud: added after the server starts, so
        Railway's health check is not delayed. Building them does not
        stop billing.
      - Owner dashboard counts "today" with an indexed time range.
      - Closing History "Void after close" (from V204) and the kitchen
        print check (from V205) rewritten to use indexes — both were my
        own slow queries.

 No change in screens. Retail and reports: no change.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V206.bat into the project folder
     (the folder that has VERSION, public, src, scripts).
  2. Double-click it, press Y. It deletes ONLY these 7 files:

  VERSION
  RELEASES.json
  src\DB.php
  src\Services\PageData.php
  src\Services\OpsService.php
  src\Services\PrinterService.php
  scripts\migration_order.php

  (scripts\migrate_speed_v206.php is a NEW file — nothing to delete.)


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V206.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                               Search for
  ---------------------------------  ---------------------------------------
  VERSION                            V206 build 2026-11-18
  RELEASES.json                      "V206 build 2026-11-18", "for": ["RESTAURANT"]
  src\DB.php                         SET time_zone =
  src\Services\PageData.php          $d0=$date.' 00:00:00';
  src\Services\OpsService.php        FROM payments pp JOIN orders o ON o.id=pp.order_id
  src\Services\PrinterService.php    AND status='FAILED'  AND queued_at
  scripts\migration_order.php        migrate_speed_v206
  scripts\migrate_speed_v206.php     ix_ord_site_closed   (new file)

  Every changed file also carries: V206 build 2026-11-18


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V206 database clock = app clock, sale point speed indexes"
      git push
  Railway deploys by itself. The new indexes are added in the
  background a few seconds/minutes after start (check the Railway
  deploy log for "migrate_speed_v206: added=4").

OFFLINE (restaurant shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.
  The update runs all migrations (including the new indexes).

After deploy: on each POS PC press Ctrl+F5 once.
POS > System > Version must show "POS V206 build 2026-11-18".


############################################################
 TESTS
############################################################

 New (9), with 2 years of history (300k bills):
  - history present; 4 indexes created by the migration; migration runs
    in the background stage (no health-check timeout); runs twice safely.
  - every sale-point screen under its limit (bill 25 ms, POS open 28,
    account preview 35, Duplicate 21, dashboard 77, Closing History 9,
    tablet tables 3, kitchen check 9 ms).
  - rush, 3 counters: 604 bills, all saved, 50% under 71 ms, 95% under
    93 ms.
  - same rush with owner dashboard + Closing History + account preview +
    kitchen poll running: 384 bills, all saved, longest wait 213 ms.
  - database clock 8 hours behind (night on cloud): account, bill and
    expense all dated today; account in today's Closing History; today's
    Sales Summary filled.

 Regression (all pass): V205 25/25 + 5/5, V204 27/27 + 7/7, V203 36/36
  + 6/6, V202 21/21 + 9/9, V199 10/10, V200 20/20 + 6/6, V201 14/14 +
  5/5, removed item 17/17 + 6/6, POS screen 13/13, tablet 6/6, rush
  17/17 + 17/17 big, counters + QR + tablets 7/7 (1 and 8 workers),
  login 15/15, business isolation 9/9, POS 13/13, accounts & closing
  reports 26/26, owner app 19/19 + 22/22, retail 113/113 + screen 36/36.

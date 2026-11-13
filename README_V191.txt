============================================================
 V191 — RETAIL (SUPERMARKET) FIXES ONLY
 Wabwar Software House                     build 2026-11-13
============================================================

REQUIRES: V190 (the complete V190 package) already installed.
Check: the VERSION file must say "V190 build 2026-11-12" before
you start, and "V191 build 2026-11-13" after.

RESTAURANT IS NOT AFFECTED. Every change is either in a retail
file, or behind a check that only runs for a RETAIL business.
No database migration. No new table or column. Nothing to run
after copying the files.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
 (Windows "Replace or Skip" breaks the extraction otherwise)
############################################################

  1. Copy DELETE_OLD_FILES_V191.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it. It lists the files, asks Y/N, deletes ONLY
     those files, and stops without deleting anything if it is
     not in the project folder.

  Files it deletes:
  VERSION
  public\api.php
  public\router.php
  src\Services\ModuleBridge.php
  src\Services\RetailCatalog.php
  src\Services\RetailDocs.php
  src\Services\RetailPos.php
  src\Services\RetailReportService.php
  src\Services\RetailShift.php      (new in V191 - "not found" is ok)
  src\Services\Sync.php
  approved_ui\retail\closing.html
  approved_ui\retail\doc.js
  approved_ui\retail\index.html
  approved_ui\retail\module_config.js
  approved_ui\retail\pos.html
  approved_ui\retail\retail_api.js
  approved_ui\retail\shift.html


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

Extract into the folder that contains VERSION, public\, src\
and approved_ui\. Folder structure inside the zip matches.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                                      Search for
  ----------------------------------------  ---------------------------------------------
  VERSION                                   V191 build 2026-11-13
  public\api.php                            function retailSaveGuard
  public\router.php                         ?b=r7
  src\Services\ModuleBridge.php             function saveRetailCustomer
  src\Services\RetailCatalog.php            V191 — STOCK SIRF RetailStock SE
  src\Services\RetailDocs.php               V191 — RETURN (RET) KE PEHRE
  src\Services\RetailPos.php                public static function returnable
  src\Services\RetailReportService.php      Cash = cash taken minus change given
  src\Services\RetailShift.php              final class RetailShift
  src\Services\Sync.php                     V191 — RETAIL: CLOUD KA PURANA STOCK
  approved_ui\retail\closing.html           rshift-list
  approved_ui\retail\doc.js                 function loadReturnable
  approved_ui\retail\index.html             retail-dash
  approved_ui\retail\module_config.js       0 = no limit
  approved_ui\retail\pos.html               function resetBill
  approved_ui\retail\retail_api.js          NO bill will be saved
  approved_ui\retail\shift.html             rshift-current


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, in the project folder, CMD:
      git add -A
      git commit -m "V191 retail fixes"
      git push
  Railway deploys by itself. Nothing to run on the server.

OFFLINE SHOPS
  Publish the update the way you publish every update. At the shop:
  Where: shop PC — double-click GET_UPDATE.bat, then INSTALL_UPDATE.bat.
  No migration is needed for V191.

Browser cache: retail JS/CSS now load with ?b=r7, so cashiers get
the new files without clearing the cache.


############################################################
 WHAT WAS FIXED
############################################################

CRITICAL
 1. POS on a failed start no longer runs on demo data.
    Before: if the first server call failed (for example
    "1040 Too many connections"), the POS showed demo items and
    "saved" bills only in the browser — nothing in the database.
    Now: 3 tries; if still no server, a red bar says NO bill will
    be saved, the cart cannot be paid, and the page reloads by
    itself when the server is back. Applies to all retail screens.
 2. Card overpayment no longer creates cash change.
    Before: card 10,000 on a 200 bill saved change 9,800.
    Now: card cannot be more than the bill; card is pre-filled
    with the bill amount; change only comes from cash.
 3. Cash figures are real cash.
    Reports, cashier/counter reports and the dashboard now count
    cash taken MINUS change given (and minus cash refunds in the
    daily summary). Dashboard "+30000" removed.
 4. Server-side permissions for every retail action.
    Selling needs the POS module, stock count needs Stock Count,
    GRN needs GRN, returns need Returns, khata receipt needs Khata,
    product/price changes need Products (or Pricing/Stock/Scale).
    Cashier discount limit: 10% of the bill (managers and owners
    have no limit). The POS warns BEFORE taking payment.
    To change the limit for one branch, run in the Railway
    dashboard -> MySQL service -> Query (site_settings syncs to
    the shop PC by itself):
      INSERT INTO site_settings(id,tenant_id,site_id,setting_group,
        setting_key,value_json,is_secret)
      VALUES(UUID(),'<tenant id>','<site id>','retail',
        'cashier_max_discount_pct','15',0)
      ON DUPLICATE KEY UPDATE value_json='15';
 5. Retail shifts are real.
    Shift screen opens/closes a real shift (cashier_shifts) with
    opening cash, counted cash, expected cash and variance.
    Expected = opening + cash sales (net of change) + khata cash
    received - cash refunds. The first bill opens a shift by
    itself (opening 0) so the counter is never blocked.
    Shift Closing History shows retail shifts.
 6. Returns.
    - Choosing the original bill loads its items and remaining qty.
    - Refund rate is the rate the customer paid (server decides).
    - A bill cannot be returned more than once (checked again at
      posting time).
    - Credit (khata) bills reduce the customer's khata; cash bills
      are cash refunds and come off the shift's expected cash.
    - Tax-inclusive refund no longer adds tax on top
      (3,050 bill = 3,050 refund, was 3,152).
    - Partial return marks the bill "Partly Returned".
    - Return without a bill: managers only, rate capped at the
      item's sale price.
    - Daily sales summary has "Returns" and "After returns".
 7. Retail customer form saves everything.
    Credit limit, Retail/Wholesale type, area, loyalty points,
    status and opening balance (new customers only, with a khata
    ledger entry). Credit limit 0 = no limit (the form used to say
    "cash only" while the server allowed unlimited credit — the
    form text now matches the server, so no running shop is
    blocked).

HIGH
 8. Receipt header comes from Settings (business name, branch,
    address, phone, NTN, header/footer). Removed the fixed
    "METRO SUPERSTORE / NTN 1234567-8" and "FBR: PENDING".
    Print button now prints (80 mm). Start Chrome with
    --kiosk-printing for silent printing.
 9. Several counters at the same moment: all bills save with
    unique numbers (was: 4 of 6 got a raw SQL error).
10. Stock only changes through stock documents.
    Editing a product no longer changes stock (opening stock is
    for new items and is recorded as an OPENING stock move).
    Cloud-to-shop sync no longer overwrites shop stock.
11. Held bill comes back with its own customer; after hold the
    next bill starts as walk-in.
12. Payment screen refreshes prices from the server, so the
    screen, receipt and database always agree. Ctrl+Enter charges
    the server total.
13. Pack/carton barcode: screen and server use the same rate
    (pack price / pack size). Was: screen 109,440, server 4,800.

SMALL
 - Real bill number, counter and cashier on the POS top bar
   (was INV-20905 / Counter 1 / Ali Raza). Click the counter chip
   to set which counter this computer is.
 - After a Wholesale customer's bill, the next bill is back on
   Retail prices (was staying on Wholesale).
 - Inactive items cannot be sold; PCS items cannot be sold as 2.5.
 - Duplicate bill searches the server (was last 100 bills only).
 - Dashboard: real today/yesterday, hourly chart, units, margin.


############################################################
 NOT DONE IN V191 — NEEDS YOUR DECISION
############################################################

 - Promotions and loyalty points are saved but not applied at
   the counter. Needs the business rules (points per rupee,
   redemption, which promotion types first).
 - Stock is one number per product for the whole business, not
   per branch. Multi-branch retail stock needs a design.
 - No screen to create pack/carton barcodes (rtl_product_uom).
 - The "Tier" field on the customer form has no database column.
 - FBR is not connected for retail bills.


############################################################
 TESTS (run on a full V191 copy, MariaDB, real browser clicks)
############################################################

 55 of 55 retail tests pass, including every audit finding re-run.
 41 retail screens open with no error; 32 retail reports run.
 Tenant isolation (cloud mode, two businesses): every cross-
 business attempt blocked.
 Restaurant: all 51 screens behave exactly as in V190; restaurant
 customer save/list unchanged; restaurant POS code not changed.

============================================================

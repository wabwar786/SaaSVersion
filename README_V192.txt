============================================================
 V192 — RETAIL: EXCEL IMPORT, FAST CATALOG, POS SEARCH,
        UPDATE NOTICE PER BUSINESS TYPE
 Wabwar Software House                     build 2026-11-13
============================================================

REQUIRES: V191 installed. Check: VERSION must say
"V191 build 2026-11-13" before you start, and
"V192 build 2026-11-13" after.

RESTAURANT: no restaurant screen or restaurant code path changes.
The only shared pieces are the update check (see section 4) and
retail-only blocks inside public/api.php and public/router.php.
No database migration. Nothing to run after copying the files.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  1. Copy DELETE_OLD_FILES_V192.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY the files below
     and stops without deleting anything if it is not in the
     project folder. Files that are new in V192 show "not found".

  VERSION
  RELEASES.json                                  (new)
  GET_UPDATE_FORCE.bat                           (new)
  public\api.php
  public\router.php
  scripts\self_update.php
  src\Services\RetailCatalog.php
  src\Services\RetailImport.php                  (new)
  src\Services\RetailStock.php
  src\Services\UpdateCheck.php
  approved_ui\retail\doc.js
  approved_ui\retail\import_products.html        (new)
  approved_ui\retail\index.html
  approved_ui\retail\labels.html
  approved_ui\retail\module.js
  approved_ui\retail\module_config.js
  approved_ui\retail\pos.html
  approved_ui\retail\products.html
  approved_ui\retail\retail_api.js
  approved_ui\retail\xlsx.full.min.js            (new)
  approved_ui\retail\licenses\SHEETJS_LICENSE.txt (new)


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                                       Search for
  -----------------------------------------  ------------------------------------------
  VERSION                                    V192 build 2026-11-13
  RELEASES.json                              "V192 build 2026-11-13", "for": ["RETAIL"]
  GET_UPDATE_FORCE.bat                       set AIO_UPDATE_FORCE=1
  public\api.php                             case 'rimport-commit':
  public\router.php                          'import_products.html'=>'products'
  scripts\self_update.php                    echo "note: "
  src\Services\RetailCatalog.php             public static function productsPage
  src\Services\RetailImport.php              final class RetailImport
  src\Services\RetailStock.php               public const IMPORT
  src\Services\UpdateCheck.php               private static function industry
  approved_ui\retail\doc.js                  var picked = null, lastHits = []
  approved_ui\retail\import_products.html    Import Items from Excel
  approved_ui\retail\index.html              S.productAgg&&S.productAgg()
  approved_ui\retail\labels.html             var shown={};
  approved_ui\retail\module.js               function paintServer()
  approved_ui\retail\module_config.js        aggScale: true
  approved_ui\retail\pos.html                S.searchProductsAsync(v,20
  approved_ui\retail\products.html           id="btnImportXls"
  approved_ui\retail\retail_api.js           S.searchProductsAsync = function
  approved_ui\retail\xlsx.full.min.js        xlsx.js (C) 2013-present SheetJS
  approved_ui\retail\licenses\SHEETJS_LICENSE.txt   Apache License


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway) — deploy the cloud FIRST (the update notice reads
RELEASES.json from the cloud).
  Where: your local PC, in the project folder, CMD:
      git add -A
      git commit -m "V192 retail import, fast catalog, POS search"
      git push
  Railway deploys by itself.

OFFLINE SHOPS (retail)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

Browser cache: retail files now load with ?b=r8.


############################################################
 1 · EXCEL IMPORT  (Products -> "Import from Excel")
############################################################

 - Files: .xls, .xlsx, .csv. The file is read on the computer
   (SheetJS, shipped inside the package, works offline).
 - Step 1: choose file and sheet; the header row is detected and
   can be changed; the first rows are shown.
 - Step 2: for every item field choose the Excel column, with three
   real sample values shown. Two modes: barcode and name in separate
   columns, or in ONE column ("barcode-name", like the Crystal stock
   report). Mappings can be saved by name and re-applied.
 - Step 3: check. New / Already in system / Check / Errors /
   Skipped. Rows where the barcode/name split is not certain are
   held as "Check": correct the barcode or name, press OK, or Skip.
 - Step 4: import in batches of 200 with a progress bar; rows that
   were not imported can be downloaded as CSV.

 Rules (as agreed):
  1. A barcode is never duplicated. Identical repeated rows are
     imported once; repeated barcodes with different data go to
     "Check".
  2. Barcode already in the system: ONLY quantity and prices
     (sale, cost) change. Quantity is set through an IMPORT stock
     move (before -> after), visible on the stock card.
  3. Sale price 0: item is Inactive (cannot be sold).
  4. Department, category, brand matched by name (case-insensitive);
     created if missing. Unit matched by code (PCS, KG...), else PCS.
 - Only the owner or a manager can import (checked on the server).
 - Importing the same file again creates no new items.

 Tested with mart17stock.xls (13,994 rows): 13,932 items imported
 in about 17 seconds; 5 identical duplicates imported once; 6 items
 with sale price 0 set Inactive; 57 rows held for "Check". Stock
 value in the system equals the file total minus the held and
 duplicate rows to the paisa (36,330,125.89).
 Note: if a barcode column in an .xlsx is stored as a NUMBER, Excel
 itself drops leading zeros — keep barcode columns as text.


############################################################
 2 · LARGE CATALOG SPEED (tested with 56,000 items)
############################################################

 Before: Products, Pricing, Stock, Scale, Batches, Brands,
 Departments took about 9.5 s to open (35 MB sent to the browser),
 and while one was opening, a bill at the counter took 7.8 s.
 Now: these screens load one page (50/100/200) from the server;
 KPI numbers and department/brand counts are counted on the server.
   - screens open in about 0.7 s (Batches 1.4 s)
   - a bill while Products is opening: 16-65 ms (was 7,791 ms)
   - Dashboard, Labels and GRN item picker no longer load the whole
     list; Wholesale / F10 repricing on the POS is 17 ms (was 8.5 s).


############################################################
 3 · POS ITEM SEARCH
############################################################

 - Typing no longer searches (before: one blocking request per
   letter; on a busy PC 7 letters took 2.2 s to type).
 - Enter: barcode / SKU / PLU first (scanner works as before);
   otherwise a name search with 3 or more letters — 20 results,
   the screen never freezes. Fewer than 3 letters: a message.
 - Price check (F9), GRN item picker and Labels search wait until
   typing pauses (300 ms) instead of searching on every letter.


############################################################
 4 · UPDATE NOTICE ONLY FOR THE RIGHT BUSINESS TYPE
############################################################

 - RELEASES.json (project root, on the cloud) says which business
   type each build is for. A build that is not listed is shown to
   everyone.
 - A shop PC now sends its business type and its build; the cloud
   answers whether a newer build for THAT business type exists.
   Example tested: restaurant on V190 + cloud on V192 (retail only)
   -> "No update for restaurant", no notice. Retail -> notice.
 - The download is always the full current package, so builds in
   between are included automatically.
 - GET_UPDATE_FORCE.bat downloads the newest build even when it was
   published for the other business type (use only when needed).
 - IMPORTANT: a shop gets this behaviour after it has installed
   V192 once. Restaurant shops still on V190/V191 run the old check
   and will see THIS update one last time.

 With every future build: add one line at the top of RELEASES.json
 ("for": ["RETAIL"], ["RESTAURANT"] or both) before deploying.


############################################################
 TESTS (full V192 copy, MariaDB, real browser clicks)
############################################################

 - All 55 V191 retail tests re-run on V192: pass.
 - Import: real file above; second .xlsx with Department/Brand/Unit
   columns (update of existing item, sale 0 -> Inactive, bad price
   and empty barcode rejected, department matched case-insensitive,
   saved mapping restored); re-import creates no items; a cashier
   is refused on screen and on the server.
 - A real offline package was downloaded from the cloud, run sealed
   on its own database: import page, SheetJS, import of the real
   file, POS name search and a sale all work.
 - Tenant isolation (cloud mode, two businesses) on every new
   endpoint: nothing from the other business is visible or changed.
 - 42 retail screens open with no error at 56,000 items; 32 retail
   reports run.
 - Restaurant: all screens behave exactly as in V190/V191.

============================================================

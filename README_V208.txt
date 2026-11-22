============================================================
 V208 — RETAIL: PURCHASE ENTRY
 Wabwar Software House                     build 2026-11-22
============================================================

REQUIRES: V207 installed. Check: VERSION must say
"V207 build 2026-11-22" before you start, and
"V208 build 2026-11-22" after.

This update is for RETAIL businesses. The update notice is shown to
retail businesses only. Restaurant screens and behaviour do not change.
Restaurant offline shops do not need this update.

WHAT IS NEW (retail):

 1. New screen: Stock & Purchasing > Purchase Entry
    The supplier's sales-tax invoice is entered line by line, in the same
    columns as the mart's PO sheet:
      Qty, Price, Total, Disc % / amount before GST, GST % / amount,
      Disc % / amount after GST, Advance tax (W.H.T 236H) % / amount,
      Net amount, Net unit cost, Prev cost, Mkt price, Sale price,
      Current price, Profit %, Profit per unit, Final total.
    - Every pair can be typed from either side: type the % and the amount
      is worked out, or type the amount and the % is worked out. The same
      for Price / Total and Sale price / Profit % / Profit per unit.
    - Scan a barcode or type an item name (F2). Barcodes keep their
      leading zero (0199628085794).
    - Choose an open purchase order to load its lines, or leave
      "Create new PO from this bill".
    - "+ New" next to Supplier adds a supplier (name, phone, NTN, STRN,
      filer status, payment terms, address, opening balance, default
      discount). It is selected straight away. Filer = 0.5% advance tax,
      non-filer = 1%.
    - Created by / Approved by / Verified by are shown at the top.
    - Keys: F2 scan, F5 refresh, F8 row update, Ctrl+S save draft.
    - Uses the existing "Goods Receipt (GRN)" permission.

 2. Check against the supplier bill
    The bill's Discount, GST (Tax value), Sub total, W.H.T 236H / Adv tax
    and Invoice total are entered at the bottom. Posting is blocked until
    they match the lines. Allowed rounding: Rs 1 on discount and GST,
    Rs 0.50 on advance tax, Rs 0.50 per line on sub total and invoice
    total (the supplier rounds each line). When GST does not match, the
    lines whose GST was calculated from a % are marked red.
    Tested with invoice C-1G2611190001 and PO 9905: the PO as entered was
    Rs 1.88 short on GST and was stopped; with the GST amounts from the
    bill it matched (difference Rs 0.22, rounding) and posted.

 3. Posting (manager or owner only, with "Confirm" ticked)
    One step, all or nothing:
      - stock goes up, average cost uses the net unit cost (after
        discounts, GST and advance tax), batches as before;
      - the supplier balance goes up by the bill's invoice total;
      - new sale prices and market prices go to the product and the POS,
        and each price change is written to the Audit log;
      - "Approved by" = the manager who posted. "Verify" can be pressed by
        a manager before or after posting.
    The same supplier invoice number cannot be entered twice for the same
    supplier. A posted purchase cannot be changed (post a purchase return
    for corrections).

 4. Goods Receipt (GRN) screen: unchanged. Purchase entries are stored as
    goods receipts (GRN-2026-0001 …) and appear in its list, but they can
    only be changed or posted from Purchase Entry.

 Database: new columns only on rtl_docs and rtl_doc_lines (retail
 tables), and four empty columns on suppliers (rtl_strn, rtl_filer,
 rtl_adv_pct, rtl_disc_pct) that restaurant code does not use. They are
 added automatically by the update (migrate_retail_purchase_v208) and
 again on the first use of the screen if needed.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################

  Where: your local PC, project folder.
  1. Copy DELETE_OLD_FILES_V208.bat into the project folder
     (the folder that has VERSION, public, src, approved_ui).
  2. Double-click it, press Y. It deletes ONLY these 8 files:

  VERSION
  RELEASES.json
  src\Services\RetailDocs.php
  public\api.php
  public\router.php
  approved_ui\retail\shell.js
  scripts\migration_order.php
  scripts\check_module_groups.php

  New files (nothing to delete):
  src\Services\RetailPurchase.php
  approved_ui\retail\purchase_entry.html
  scripts\migrate_retail_purchase_v208.php


############################################################
 STEP 2 — EXTRACT THE ZIP INTO THE PROJECT ROOT
############################################################

  Where: your local PC. Extract SaaSVersion_V208.zip into the same
  project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################

  File                                      Search for
  ----------------------------------------  ----------------------------------
  VERSION                                   V208 build 2026-11-22
  RELEASES.json                             "V208 build 2026-11-22", "for": ["RETAIL"]
  src\Services\RetailPurchase.php           public static function billCheck
  src\Services\RetailDocs.php               public static function postInTx
  public\api.php                            case 'rpurchase-post'
  public\router.php                         'purchase_entry.html'=>'grn'
  approved_ui\retail\purchase_entry.html    Bill mismatch — posting blocked
  approved_ui\retail\shell.js               'Purchase Entry', 'purchase_entry.html'
  scripts\migrate_retail_purchase_v208.php  RETAIL_PURCHASE_V208_OK
  scripts\migration_order.php               migrate_retail_purchase_v208
  scripts\check_module_groups.php           5 hisson wala item

  Every changed file also carries: V208 build 2026-11-22


############################################################
 STEP 4 — DEPLOY
############################################################

CLOUD (Railway)
  Where: your local PC, project folder, CMD:
      git add -A
      git commit -m "V208 retail Purchase Entry"
      git push
  Railway deploys by itself. The deploy log shows
  "migrate_retail_purchase_v208 RETAIL_PURCHASE_V208_OK".

OFFLINE (retail shops)
  Where: shop PC — GET_UPDATE.bat, then INSTALL_UPDATE.bat.

After deploy: press Ctrl+F5 once in the browser.
Purchase Entry shows "Purchase Entry · V208 build 2026-11-22" at the
bottom right.


############################################################
 TESTS
############################################################

 New — Purchase Entry API 51/51:
  - supplier popup (required name and phone, duplicate name refused,
    opening balance in the ledger, filer adv tax 0.5%);
  - barcode with leading zero, name search, prev cost;
  - server maths = mart PO 9905 line by line (GST 353.67, 293.19, 516.67,
    679.68, 712.21; net 1,777.17 … 3,067.10; total 21,348.56);
  - post refused: without Confirm, without bill total, GST off by 1.88,
    by a store keeper, by GET; GRN screen cannot post or change it;
  - post with bill GST amounts: computed 21,350.44, supplier credited
    21,350.22, balance 548,625 -> 569,975.22 (bill Grand Total 569,975),
    stock +42, cost 525.78 incl. GST and adv tax, sale price 600 -> 580
    with Audit log, 8 stock moves, Approved by the poster, in GRN list;
  - posted entry locked, posting twice does not double stock, same
    invoice refused, Verify by manager only, next prev cost = 525.78;
  - open PO listed, lines loaded, PO closed after posting;
  - server ignores screen totals; zero qty / negative GST refused;
    total typed -> rate, disc amount typed -> %;
  - another business's product / supplier refused; restaurant business
    gets "not a retail store".
 New — Purchase Entry screen 23/23: version shown, sidebar link, popup
  supplier selected, scan, % <-> amount both ways, profit % -> sale
  price, post blocked until the bill matches, Ctrl+S draft, post locks
  the screen, Verify, reopen read-only, Open list, Compact, New, name
  search pick list, no page errors.

 Regression (all pass): retail 113/113 + screens 149/149; restaurant
  V207 sync 55/55, V205 25/25 + 5/5, V204 27/27 + 7/7, V203 36/36 + 6/6,
  V202 21/21 + 9/9, V199 10/10, V200 20/20 + 6/6, V201 14/14 + 5/5,
  removed item 17/17, POS screen 13/13, tablet 6/6, login 15/15,
  business isolation 9/9, accounts & closing 26/26, permissions 3/3,
  owner app 19/19 + 22/22. Shop PC update run: all migrations ok,
  module menu check OK.

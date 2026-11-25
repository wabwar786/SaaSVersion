============================================================
 V212 — RETAIL: SUPPLIER PAYABLES (BACK OFFICE)
 Wabwar Software House                     build 2026-11-25
============================================================

REQUIRES: V211 installed. VERSION must say "V211 build 2026-11-25"
before you start, and "V212 build 2026-11-25" after.

RETAIL only. Restaurant screens and behaviour do not change. The
update notice is shown to retail businesses only. Restaurant
offline shops do not need this update.
Two shared files are in this package:
  - public\api.php gets only new retail actions (rpay-...). Each one
    first checks that the business is retail.
  - public\router.php gets only retail lines: new page names, and the
    new style/script files added to the retail cache list.
The cash counter (POS) is not touched.

DATABASE: new tables are created by themselves on first use:
  rtl_pay_slips, rtl_pay_slip_bills, rtl_supplier_payments,
  rtl_supplier_pay_alloc, rtl_doc_prints
and one empty column suppliers.rtl_pay_info (bank / wallet).
Nothing old is changed or deleted. No script to run.

WHAT IS NEW (menu: Stock & Purchasing, Finance & Reports)

 1. Suppliers (replaces the old page)
    Form on the left, list on the right. Opening balance with an
    "as of" date goes into the ledger once. It can be changed only
    until the supplier's first purchase or payment (then it is locked).
    The balance is always the ledger balance — it is not typed any
    more. Terms (Cash / Credit 7 to 60 days), NTN, STRN, Filer,
    bank / wallet. If the same name exists, you are asked.

 2. Payment slip (Inventory) — Purchase Entry (posted) and Purchase
    Register: "Payment slip" button.
    - Pay now: the supplier goes to Accounts today.
    - Pay on date: comes back on / after the date (due date from the
      supplier's terms, can be changed).
    - One or more bills of one supplier; the opening balance can also
      go on a slip.
    - A bill already on an open slip cannot go on a second slip.
    - Printed twice: Supplier copy + Office copy, with a barcode.

 3. Payment Slips (Accounts) — due today, overdue, next 7 days, paid
    this month. Scan the slip number + Enter: the payment opens.
    Cancel an unpaid slip, or "Close rest" of a part-paid slip:
    manager / owner only, reason required.

 4. Supplier Payment (Accounts — permission "Accounting / Cash")
    - Scan the slip: supplier, bills and amount fill in by themselves.
    - Cash = CPV (Cash Payment Voucher). Cheque, Bank, JazzCash,
      Easypaisa = BPV (Bank Payment Voucher). Cheque needs bank,
      cheque no. and date. JazzCash / Easypaisa need the transaction ID.
    - The amount can be LESS than the slip, e.g. 40,000 of 50,000. The
      slip becomes "Part paid" and the rest stays payable on the same
      slip.
    - Without a slip: pick the supplier, type the amount, and it goes
      on the oldest bills first (any line can be changed by hand).
    - More than the bills: the extra is kept as an advance and is used
      first on the next payment.
    - A "Pay on date" slip paid before its date needs a manager / owner
      (tick "Pay early"). The voucher shows who allowed it.
    - Voucher print: amount in words, which bills were paid, balance
      before and after, slip number, 3 signatures.
    - Cancel: manager / owner only, reason required. The payment stays
      visible and a reverse entry is added to the ledger. Money that
      had come from an older advance goes back to that advance.
    - Paid from Office / Accounts only. The cash counter is not used.

 5. Supplier Ledger — from / to dates, filter by Purchases / Payments
    / Returns. Shows opening balance, every entry with a running
    balance, and closing balance.
    Entries go by the bill date and the payment date.
    Print statement and Excel. Double-click a payment to reprint its
    voucher.

 6. Supplier Balances — every supplier's balance with ageing (0–30,
    31–60, 61–90, 90+ days), overdue (by credit days), due in the
    next 7 days, paid this month. Ledger / Slip / Pay from each row.

 7. Purchase Register — search ANY purchase:
    - by supplier bill no., our ref no., supplier, date range, item
      name or barcode, payment status (Unpaid / Part paid / Paid /
      Overdue) and Posted / Draft.
    - Each bill shows bill total, returns, paid and balance.
    - "Print copy" prints the purchase with DUPLICATE on top; every
      print is logged (copy number, who, when).
    - Excel and print list.

 Already there and unchanged: the same supplier bill no. cannot be
 posted twice (Purchase Entry), and stock goes up on Post.

 NOTE — offline shop PCs: slips and payments made on a shop PC stay
 on that PC; they do not go up to the cloud. Make payments on the
 cloud (back office), as agreed.


############################################################
 STEP 1 — DELETE THE OLD FILES (one click)
############################################################
  Where: your local PC, project folder.
  Copy DELETE_OLD_FILES_V212.bat into the project folder, double-click,
  press Y. It deletes ONLY these 7 files:

  VERSION
  RELEASES.json
  public\api.php
  public\router.php
  approved_ui\retail\shell.js
  approved_ui\retail\suppliers.html
  approved_ui\retail\purchase_entry.html

  New files (nothing to delete):
  src\Services\RetailPayables.php
  approved_ui\retail\pay.css
  approved_ui\retail\pay_common.js
  approved_ui\retail\supplier_balances.html
  approved_ui\retail\supplier_ledger.html
  approved_ui\retail\supplier_payment.html
  approved_ui\retail\payment_slips.html
  approved_ui\retail\purchase_register.html


############################################################
 STEP 2 — EXTRACT SaaSVersion_V212.zip INTO THE PROJECT ROOT
############################################################
  Where: your local PC, same project folder.


############################################################
 STEP 3 — CONFIRM EACH FILE (Ctrl+F for the string)
############################################################
  File                                        Search for
  ------------------------------------------  -------------------------------
  VERSION                                     V212 build 2026-11-25
  RELEASES.json                               "V212 build 2026-11-25", "for": ["RETAIL"]
  src\Services\RetailPayables.php             public static function cancelPayment
  public\api.php                              case 'rpay-pay'
  public\router.php                           'supplier_payment.html'=>'accounting'
  approved_ui\retail\shell.js                 'payment_slips', 'Payment Slips'
  approved_ui\retail\pay.css                  .pw .grid3
  approved_ui\retail\pay_common.js            P.slipDialog
  approved_ui\retail\suppliers.html           rpay-supplier-save
  approved_ui\retail\supplier_balances.html   rpay-balances
  approved_ui\retail\supplier_ledger.html     rpay-ledger
  approved_ui\retail\supplier_payment.html    Pay early
  approved_ui\retail\payment_slips.html       Close rest
  approved_ui\retail\purchase_register.html   rpay-register
  approved_ui\retail\purchase_entry.html      btnSlip

  Every file also carries: V212 build 2026-11-25


############################################################
 STEP 4 — DEPLOY
############################################################
CLOUD (Railway) — your local PC, project folder, CMD:
    git add -A
    git commit -m "V212 retail supplier payables"
    git push
OFFLINE (retail shops) — shop PC: GET_UPDATE.bat, then INSTALL_UPDATE.bat.
After deploy press Ctrl+F5 once. Every new page shows
"<page name> · V212 build 2026-11-25" at the bottom right.

WHO SEES WHAT (Users & Access, existing permissions):
  Payment slip button, Purchase Register   -> "Goods Receipt (GRN)"
  Payment Slips, Supplier Payment          -> "Accounting / Cash"
  Suppliers, Supplier Balances, Ledger     -> "Suppliers"
  Cancel slip / payment, pay a slip early  -> manager / owner only


############################################################
 TESTS
############################################################
 New — API 71/71:
  - suppliers: opening into the ledger with the "as of" date, same
    name asks, opening locked after the first purchase;
  - same supplier bill no. twice refused;
  - open items: oldest first, due date from terms;
  - slips: bill on an open slip refused, past date refused, amount
    above the balance refused, another supplier's bill refused;
  - cheque BPV 120,000 against a 167,400 slip: opening paid first,
    then the bill; slip Part paid, 47,400 left;
  - the rest paid in cash (CPV); slip Paid; a paid slip cannot be
    paid again;
  - early slip: refused without the tick, refused for an Accounts
    user, allowed for the owner;
  - accounts user cannot print slips, cancel, or open the register;
  - cancel: reason needed, reverse ledger entry, balance back, cannot
    cancel twice;
  - advance: kept, then used first on the next payment, and given
    back when that payment is cancelled;
  - slip cancel and close rest;
  - ledger by bill / payment date, running balance = closing;
  - balances: ageing and due in 7 days;
  - register: search by bill no., supplier, item barcode, payment
    status, dates; DUPLICATE copy logged;
  - no CSRF / GET refused; a restaurant business is refused (403) on
    every new action.
 New — screens 35/35:
  - new menu items; supplier save; opening locked;
  - register search; DUPLICATE copy print;
  - slip dialog and printing 2 copies;
  - slip scan fills the payment; partial payment with confirm; cheque
    BPV and voucher (amount in words); cash CPV;
  - cancel with reason; pay-on-date slip; slips list; Enter on slip
    no. opens the payment; Pay early box; close rest; slip reprint;
  - ledger and double-click voucher reprint; statement print;
    balances;
  - Purchase Entry buttons; no page errors.
 Regression (all pass):
  - Retail: aud 113/113, screens 149/149, Item Creation 96/96 + 44/44,
    Purchase Entry 50/51 + 22/23.
  - Restaurant: sync 55/55, accounts & closing 26/26, isolation 9/9,
    login 15/15, removed item 17/17, V199–V205 all pass, permissions
    3/3, POS screen 13/13, tablet 6/6, owner app 22/22, V205 screens
    4/5.
  - The one fail in Purchase Entry 50/51 + 22/23 and V205 screens 4/5
    is the same line: it expects build V208 on screen and now reads
    V212.
 Module menu check and retail wiring check OK.

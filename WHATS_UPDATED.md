# WHATS UPDATED — V15 (DB-First Restaurant Core)

## Sab se bara change: data direction palat gayi
Pehle browser localStorage source-of-truth tha aur DB sirf best-effort shadow.
Ab **DB source of truth hai, localStorage sirf cache** (`public/db_boot.js`,
har logged-in page ke head mein inject hota hai). Faide:
- Dashboard REAL orders/payments/expenses dikhata hai (fake demo counters khatam).
- Multi-terminal consistent: har terminal DB se hydrate hota hai.
- Browser data clear hone par kuch nahi khota.

## Fixed production bugs
1. `scripts/install_schema.php` — `--` comment ke baad wale CREATE TABLE silently
   skip hote the ⇒ **14 tables (orders, customers, payment_methods, suppliers,
   printers, cashier_shifts...) create hi nahi hote the.** Ab strip-comments-first.
2. `scripts/seed_full_demo_legacy.php` — PHP 8 strict-types crash (int→preg_replace).
3. `src/Services/PageData.php` — `lines` reserved word (MariaDB 10.6+/MySQL 8) ⇒
   store-state 500 deta tha. Backticked.
4. `menu_item_variants` insert — tenant_id/site_id missing.

## POS (server-side, verified)
- `pos-boot`: products/customers/tables/categories/printers/nextBill DB se.
- **Server-authoritative bill numbers**: closed bill par dubara KOT/finalize aaye
  to server agla number assign karke `bill_no` wapis deta hai (duplicate bills
  across terminals khatam). Helper: `pos_bill_guard()`.
- KOT ab **blocking** hai: DB save fail ⇒ kitchen-sent mark nahi hota.
- `pos-quick-item`: POS ke andar item creation ab DB mein — category auto-create,
  weighted/standard, **variants**, aur inventory link:
  `inventory.mode = none | existing (deduct qty/sale) | new (naya inventory item
  + opening stock + cost, phir link)`. Transaction-wrapped.
- `menu-category-create`: category + printer route.
- Shifts: `shift-open` / `shift-current` / `shift-close` (close par expected vs
  actual cash reconciliation: opening + cash sales − cash expenses, variance).
- POS page auto: DB hydration + shift ensure + item-create modal mein inventory
  fields inject (`public/pos_db_mirror.js` v2).

## ModuleBridge — generic modules ab REAL tables par
`src/Services/ModuleBridge.php`: approved UI ka `records-*` contract wahi ka wahi,
lekin ye modules ab relational tables mein jaate hain:
- **customers** → customers + customer_addresses (POS customer list se shared —
  yahan banao, POS mein foran milta hai)
- **suppliers** → suppliers (MTD purchases REAL GRNs se, balance GRN − payments)
- **expenses** → expenses + expense_categories (dashboard/shift-close mein counted)
- **wastage** → stock_adjustments + stock_transactions ⇒ **asli stock ghat-ta hai**,
  value costed; delete FORBIDDEN (audit) — correcting entry post karo.
Baqi modules pehle ki tarah `ui_records` mein (ab site_id-scoped).

## Aur
- `ui_records` list ab site-scoped (multi-branch data mixing band).
- Demo seed ab client DB mein inject NahI hota (module.js) — demo sirf seed scripts se.
- `live_store.js` defaults = 0 (fake PKR 282,930 khatam).
- Whole-state client→DB mirror hataya (stale cache DB overwrite kar sakta tha).
- `scripts/migrate_bridge.php` (suppliers.city/category/deleted_at) — Docker
  entrypoint + Windows bootstrap dono mein hooked.

## Seed order (fresh DB)
install_schema → migrate_platform → migrate_sync → migrate_bridge →
docs/03_seed_restaurant_base.sql → seed_roles → ensure_default_admin →
seed_restaurant_demo (master data) → [optional] seed_full_demo.

## Tested (real MariaDB, ONLY_FULL_GROUP_BY on)
Login ✓ pos-boot ✓ KOT→kitchen_tickets ✓ finalize→payments+stock deduction
(recipe 2×160g ✓, direct 2×200ml ✓) ✓ closed-bill reassign (2099→2100) ✓
quick-item (new/existing inventory, variants, dupe guard) ✓ wastage 500g stock
decrement + delete-refusal ✓ expenses→dashboard ✓ shift close reconciliation
(variance ✓) ✓ suppliers/customers bridge ✓ riders ui_records fallback ✓
sync-status ✓ db_boot sirf authed pages par ✓

---

# V16 — Cloud Login Fix + Platform Enhanced

## Login fix (Railway wala "Invalid login or account not approved")
Root cause (sandbox mein cloud-mode reproduce karke): `?b=slug` ke baghair
login par cloud tenant resolve nahi hota tha — direct `login.html` kholne
par hamesha invalid.
- **Email/username se tenant auto-resolve**: exactly EK active match ho to
  wahi tenant; ek se zyada businesses same email par hon to slug lazmi
  (security — ambiguity par guess nahi hota).
- Error redirects ab `?b=slug` preserve karte hain.

## Subscription enforcement (ab expiry ka matlab hai)
- Login par: SUSPENDED business → "This business is suspended…", expired →
  "Subscription expired on <date>. Please renew…" — dono clear message ke
  saath block (login.html par show hota hai). Single enforcement point
  (`Auth::subscriptionBlock`), api-login + form-login dono par.

## Super Admin — enhanced
- Har business par actions: **Copy link · Detail · Renew · Reset Pass ·
  Suspend/Open**.
- Detail: subscriptions + payments history, users/branches count, sync token,
  client link.
- **Renew**: nayi expiry + payment record (subscription_payments).
- **Reset Pass**: business admin ka naya one-time password.
- **Change Password** (super admin) — current verify + min 8.
- **DB Health chip** + `sa-diagnostics`: missing tables/columns turant
  dikhata hai (Railway ki purani DB ka "Operation failed" ab pinpoint hoga).
- sa-* errors ab `storage/logs/api-error.log` mein bhi jaate hain, aur super
  admin ko asli error message milta hai (generic "Operation failed" sirf
  anonymous users ke liye).

## Railway deploy note
Redeploy karte hi entrypoint fixed `install_schema.php` chalayega — purani
DB ke missing 14 tables khud ban jayenge. Deploy ke baad super_admin.html
kholein: "DB healthy" chip aana chahiye.

## Tested (cloud-mode sim: AIO_CONFIG=cloud, fresh DB, entrypoint sequence)
Business create+list ✓ client-link login ✓ **slug-less login (email resolve)** ✓
ambiguous email → slug required ✓ suspend → blocked message ✓ activate ✓
expired → renew message ✓ renew+payment ✓ detail (2 payments) ✓ reset admin
password + login with new ✓ super password change (wrong-current rejected) ✓
diagnostics healthy ✓ LOCAL mode regression: form login + pos-boot +
dashboard + store + shift sab ✓

---

# V17 — Collation Fix + New-Business Defaults + POS Fixes

## 1. "Illegal mix of collations" (super admin business list)
Railway MySQL 8 par purani tables `utf8mb4_0900_ai_ci` aur nayi migrations
`utf8mb4_unicode_ci` — cross-table joins par 1267 error.
- `scripts/migrate_collation.php`: DB default + HAR table ko
  utf8mb4_unicode_ci par normalize (idempotent; entrypoint + Windows
  bootstrap dono mein hooked — **redeploy par khud fix**).
- `DB.php`: connection par `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci` pin.
- Sandbox mein exact 1267 error reproduce karke normalize→join verify hua.

## 2. POS par data kyun nahi tha — naya business EMPTY SHELL tha
Provisioning sirf tenant+site+admin banati thi. Payment methods na hone se
bill hi nahi ban sakta tha, stock location na hone se inventory posting
throw karti thi. Ab `Platform::seedSiteDefaults()` har naye business par:
- 7 payment methods (CASH/CARD/RAAST/EASYPAISA/JAZZCASH/BANK/COD)
- 2 stock locations (Main Store, Kitchen) · Main Kitchen printer
- 4 starter menu categories (routes ke saath) · Main Floor + 8 tables
- 5 units (global, agar missing) · 6 expense categories
Menu jaan-boojh kar khali — client POS se apna menu banata hai.

## 3. POS fixes
- **`activeBillNo` bug**: top-level `let` window par attach nahi hota —
  server bill numbers/hydration ka sync silently fail tha. Fixed (bare
  global binding).
- **Rate change ab DB mein persist** hota hai (`menu-item-rate` action +
  updateExistingRate wrap). Item create pehle se persist tha (V15).
- **Empty-menu state**: products na hon to "Create First Item" card, jo
  seedha Item Management modal kholta hai (search re-render par bhi rehta
  hai).
- NOTE: inventory-screen ke items POS par as-products NAHI aate — POS
  **menu items** dikhata hai. Inventory ko bechne ke liye POS ke "+ New
  Item" mein "Deduct existing inventory item" mode use karein.

## Tested (fresh business full lifecycle, cloud sim)
Create "Lahore Karahi House" → client login → pos-boot: 4 cats, 1 printer,
8 tables ✓ → quick-item "Chicken Karahi" + new inventory 20kg ✓ → rate
1400→1550 persist ✓ → pehla bill PKR 1550: KOT fired ✓ stock 20→19.5kg ✓
tenant-isolated dashboard ✓. Collation: 1267 repro→normalize→join OK ✓.
Regressions: sa-business-list ✓ diagnostics healthy ✓ local node full ✓
PHP lint clean ✓.

<!-- build: V17.1 build 2026-08-25 -->

---

# V18 — Backfill Defaults + Shift Gate + Cashier Closing Report

## "Item save hua phir gayab" — asal wajah aur fix
Railway par V17 deploy nahi hui thi, is liye purane businesses EMPTY SHELL
the (0 payment methods, 0 stock locations, 0 units). Inventory/item create
server par fail hota tha, sirf browser localStorage mein dikhta tha, aur
agla page-load DB-first hydration se use "gayab" kar deta tha.
- `scripts/migrate_site_defaults.php`: **PURANE businesses ka backfill** —
  har existing site par missing defaults ensure (idempotent; entrypoint +
  Windows bootstrap hooked ⇒ deploy karte hi sab businesses theek).
- `Platform::ensureSiteDefaults()`: har component ab individually
  idempotent (naye + purane dono ke liye ek hi code path).
- **Silent failures khatam**: db_mirror_bridge ab har failed DB save par
  wazeh toast/alert deta hai — "screen par hai, DB mein nahi" wala dhoka
  dobara nahi hoga.

## Shift Gate (aapka manga hua flow)
- POS kholne par agar shift OPEN nahi ⇒ **blocking overlay**: opening cash
  enter karo, "Open Shift & Start Billing". Iske baghair KOT/finalize dono
  block (client + clear message).
- Header chip ab LIVE shift number dikhata hai + **Close** button.
- Close par: server-computed preview (opening, orders + bill range, gross,
  per-method sales, cash expenses, expected cash) → cashier ACTUAL counted
  cash enter karta hai → close → **Shift Report** (variance green/red) →
  🖨 Print → report band hote hi agli opening ka gate.
- API: `shift-preview`, richer `shift-close`, `shift-last-report` (reprint).

## Concept (ek line mein yaad rakhein)
**Inventory = kachha maal (stock), Menu = bikne wali cheez.** POS menu items
bechta hai; inventory tab ghat-ti hai jab menu item recipe se juda ho ya
"Deduct existing inventory" link ho. Data ka rasta: UI → API → MySQL; browser
sirf cache hai — har page-load DB ka sach dikhata hai.

## Tested (royal-grill — wohi business jo empty shell tha)
Backfill ✓ inventory create (pehle 'No units configured' fail) ✓ store-state
mein item ✓ POS quick-item ✓ shift open 5000 → 2 bills (cash 1300 + card
650) → cash expense 700 → preview expected 5600 ✓ close actual 5590 ⇒
variance −10 ✓ per-method breakdown ✓ last-report reprint ✓ close ke baad
gate wapis ✓. Local node regression full ✓. PHP lint 68 files clean ✓.

---

# V19 — Menu Management + Order Taker LIVE (ek hi DB source)

## Pehle deploy zaroor karein
Aapke screenshots V16 ke hain — V17/V18 live nahi the (empty POS, demo
categories, koi shift gate nahi = wohi puraana build). V19 mein sab shamil
hai; deploy hote hi boot-migrations purane businesses ko bhi theek kar
dengi.

## Menu & Categories page ab REAL hai (ModuleBridge: 'menu')
- List: asli menu_items — price, category, **food cost auto** (recipe ho to
  recipe se, direct-inventory link ho to qty×avg cost), margin, status.
- "+ Add item": DB mein banta hai, nayi category apne aap; edit se price/
  category/status update; delete = soft delete.
- **Ek source, teen screens:** Menu page par item banao/price badlo →
  POS *aur* Order Taker Tablet dono par foran (sab `pos-boot` se parhte
  hain). Inactive karo → dono se ghayab.

## Order Taker Tablet ab LIVE hai (`public/order_taker_db.js`)
- Dummy PRODUCTS/CATS khatam — menu, categories, TABLES sab DB se.
- "Send Pending Items to Kitchen" = **asli KOT** (`pos-kot`): order banta
  hai, kitchen_tickets mein jata hai, KDS/kitchen isi se milega. DB fail
  ho to sent mark NahI hota.
- Table select DB ki dining_tables se; table change/new order par naya
  server bill; waiter naam session user ka.
- Waiter-only login ke liye `tablet` module bhi pos-boot/pos-kot par
  allowed.

## POS polish
- Categories ab hamesha DB se replace (0 hon to bhi) — demo categories ka
  dhoka khatam; sirf "All Items" + Create-First-Item card.
- Header par asli cashier ka naam/initials/role (static "Ali Raza" gaya).

## Tested (royal-grill)
Menu page se "Chicken Shawarma" create (450) → price edit 499 → pos-boot
mein dono items real price ke saath ✓ tablet page par bridge inject ✓
tablet-style KOT Table 3 → order + kitchen_ticket DB mein ✓ Inactive
toggle → POS list se ghayab, Active par wapis ✓ cashier naam real ✓
local-node regression (login/pos-boot/menu-list/store/dashboard) ✓ PHP
lint clean ✓

---

# V19.2 — Visible POS/Tablet Enhancements + Cache-proofing

## Ab har change NAZAR aayega
- **Status strip** POS header ke neeche (naya visible layer): Branch naam ·
  Shift status (green/red dot + Open/Close inline action) · live menu-item
  count · khali menu par guidance line · **version badge (POS v19.2)** —
  ab screen dekh kar hi pata chal jayega kaunsa build chal raha hai.
- Product cards: depth/hover-lift, price pill, rounded Add button;
  active category glow. (Approved layout wahi ka wahi — sirf polish layer.)
- Tablet: **demo cart ab clear hota hai**, table CARDS strip DB ki asli
  tables se (occupied/available + tap-to-select), hover polish, corner
  par "Tablet v19.2" badge.
- pos-boot ab branch (site) ka naam bhi deta hai.

## Stale-cache hamesha ke liye khatam
- HTML responses par `Cache-Control: no-store` — browser purana page
  pakar kar nahi baithega. JS `?b=` versions bump. (Phir bhi pehli dafa
  ek hard-refresh Ctrl+Shift+R kar lein.)

## Tested
pos-boot site/cashier/products/tables ✓ HTML no-store header ✓ local
regression ✓ PHP lint clean ✓ JS syntax clean ✓

---

# V20 — POS PAGE FULLY REBUILT (DB-driven, redesigned)

## "1 menu item hai magar POS par 0" — root cause mila aur fix
Wo item `ui_records` (JSON blob) mein phansa hua tha — us build se jo
ModuleBridge 'menu' se PEHLE ka tha. Menu page usay dikha raha tha,
lekin POS `menu_items` table parhta hai, is liye 0.
- `scripts/migrate_ui_menu.php`: **stranded menu rows ko asli menu_items
  mein le aata hai** (idempotent; entrypoint + Windows bootstrap hooked).
  Sandbox mein aapka exact "Burger PKR 500" case reproduce karke verify
  kiya — backfill ke baad POS par foran aa gaya.

## restaurant_pos.html — SCRATCH SE NAYA (POS v20)
Purana page demo arrays par bana tha (static categories/products), is liye
patch-layer se poori tarah control nahi aa raha tha. Ab poora page naya
hai — **koi demo data nahi, har cheez API se**:
- Categories: DB se, har category par live item count.
- Product grid: DB se — image/emoji fallback, Weighted/Options badge,
  price pill, per-card qty stepper (+/-), hover-lift cards.
- Cart: qty +/-, remove (kitchen-sent par confirm), "Kitchen sent" tag.
- Service mode (Dine In / Takeaway / Delivery), table select (DB tables),
  customer select (DB customers).
- Discount (flat/percent), Service Charge %, Sales Tax % — sab editable.
- **Charge modal**: 6 payment methods, amount received, live change return.
- **Send to Kitchen**: asli KOT; fail ho to sent mark nahi hota.
- **Hold Bills**: ab SERVER par (`held_bills`) — doosre terminal se bhi
  resume ho sakta hai, browser clear par bhi zinda.
- **Item Management** (3 tabs): Create Item (weighted / variants /
  inventory link: none|existing|new), Create Category (+printer station),
  Change Rate — teeno DB par persist.
- **Shift gate**: shift band ho to blocking overlay; close par preview →
  actual cash → variance report → print.
- Status strip: branch, shift, live item count, bill #, **POS v20** badge.
- Purana `pos_db_mirror.js` POS se unhook (ab zaroorat nahi).
- Legacy page `restaurant_pos_legacy.html` mein mehfooz.

## Tested (royal-grill, cloud sim)
Stranded "Burger" backfill → POS par visible ✓ KOT bill 0010 ✓ finalize
with discount → netSales 950, next bill 11 ✓ rate change 500→550 ✓
category "Desserts" create ✓ quick-item "Gulab Jamun" ✓ held_bills
server-side save/list ✓ boot: 4 products, 5 categories, 8 tables ✓
local node: POS v20 served, boot OK ✓ PHP lint clean ✓ POS JS syntax ✓

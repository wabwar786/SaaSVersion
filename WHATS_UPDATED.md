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

---

# V20.1 — POS Diagnostics + Item-visibility Fixes

## Ab aap KHUD verify kar sakte hain
POS par 3 jagah **"Run Diagnostics"** button hai (status strip, error banner,
khali-menu card). Ye seedhe DB se sach dikhata hai:
branch naam · is branch ke menu items · POS-visible items · DOOSRI branch ke
items · broken-category items · ui_records mein phansay items · categories /
payment methods / stock locations / tables / units counts · posBoot kitne
products return kar raha hai (ya uska asli SQL error) · recent 20 items with
active/pos flags aur branch mismatch warning. Saath "Kya karein" tip aur
**Copy Report** button.

## Do asli bug fix
1. **posBoot ka INNER JOIN**: agar item ki category delete/orphan ho jati thi
   to item POS se chup-chaap GHAYAB ho jata tha. Ab LEFT JOIN + category
   'General' fallback — item hamesha dikhega.
2. **Errors chhup rahe the**: logged-in user ko sirf "Operation failed."
   milta tha. Ab apne business ka asli error message milta hai, aur POS par
   laal banner mein bhi dikhta hai (khali screen ke bajaye).

## Tested (3 failure modes simulate karke)
A) category orphan → item ab bhi POS par ('General') ✓ diag ne broken=1 pakra ✓
B) is_pos=0 → diag ne this_site=4 vs pos_visible=3 ka farq dikhaya ✓
C) table missing → user ko asli SQL error mila (generic nahi) ✓
Normal state: 4 products, posBoot=4 ✓ local node ✓ PHP lint clean ✓ JS OK ✓

---

# V20.2 — ASAL BUG MILA: router injection ne POS ki JS tor di thi

## Root cause (aapke console error se pakra: "Uncaught SyntaxError ... :682")
POS ke `showReport()` mein print-window ka poora HTML ek JS **string** mein
likha tha, jismein closing head/body tags literal thay. Router un tags par
`<script src=...>` inject karta hai — aur wo injection **JS string ke andar**
ja ghusi. Nateeja: page ki poori JS parse-error se mar gayi, is liye:
koi products nahi, koi categories nahi, Cashier "--", koi shift gate nahi,
aur koi error banner bhi nahi (kyunki banner ka code bhi usi JS mein tha).

**Yehi bug purane POS page mein bhi tha** — is liye mere pichhle patches
(categories replace, mirror) us par kabhi poora asar nahi kar rahe thay.

## Fix (teen level par)
1. `showReport()` ab DOM API se print window banata hai — koi HTML document
   string nahi.
2. **Router hardened**: ab sirf PEHLE closing-head aur AAKHRI closing-body par
   inject karta hai (pehle `str_replace` har occurrence par karta tha).
3. **`tools/check_pages.php`** — guard script: approved_ui ki har page ke
   inline scripts scan karke aisa literal tag pakarti hai. Isi ne legacy POS
   page ka same bug foran pakra (wo ab `docs/` mein bhej diya gaya).

## Asli browser (headless Chromium) se verify kiya — pehli dafa
Sirf API nahi, poora page render karke:
page errors: **0** · strip: "Main Branch | Shift S-... | 4 menu items |
Bill #0011 | POS v20.2" · cashier: Ahmed Khan · categories with counts:
All 4, Fast Food 3, Desserts 1 · products: 4 ✓
Cart add → PKR 650 ✓ search "gulab" → filter ✓ category filter ✓
Diagnostics modal (13 rows) ✓ Item Management 3 tabs + inventory fields ✓
naya item create → grid mein foran ✓ Charge modal 6 payment methods ✓

---

# V21 — Legacy layout + poora legacy feature set, DB par

## Analysis
**Legacy page ka faida:** dense layout, zyada options, achha space use,
Quantity Calculator, variants+kitchen comments, slip preview/print,
customer/table modals, void approval, drawer nav, proper QTY/DESCRIPTION/
RATE/TOTAL bill table. **Nuqsan:** poora demo data, aur uski JS bhi router
injection se tooti hui thi.
**v20 ka faida:** sab kuch DB se, shift gate, diagnostics, item+inventory
create. **Nuqsan:** features kam, cards bare, space zaya.
**V21 = dono ka merge.**

## Layout / space
- Right panel 420px se **372px** — items ko ab **75% screen (7 columns)**.
- Compact cards (146px min): naam 2-line clamp, CATEGORY label, bara green
  price (`/ KG` ya `onwards` suffix ke saath), qty stepper + Add.
- Sticky category row with per-category item counts.
- Bill panel mein legacy wali **QTY | DESCRIPTION | RATE | TOTAL** table.

## Legacy features jo wapis aaye (sab DB-driven)
- **Quantity Calculator**: numpad + quick chips (0.10/0.25/0.50/0.75/1.00/
  1.50), keyboard support, Backspace/Enter. Card ke qty par ya cart ke qty
  par click karke khulta hai. Weighted items par khud khulta hai.
- **Variants + Kitchen Comments** modal (variants ab posBoot se aate hain).
- **Slip Preview**: KOT aur Bill dono ka thermal-style preview, **Print**
  (DOM API se — router-injection safe) aur **WhatsApp** send.
- **Void Approval**: kitchen-sent item remove karne par manager password +
  reason zaroori; record **Void Logs** mein (naya modal).
- **New Customer** aur **New Table** modals — dono DB mein save
  (`pos-table-create` naya endpoint), list foran refresh.
- **Duplicate Bill**: aaj ke closed bills, search + reprint.
- **Drawer navigation** (14 links), **System** modal (branch/cashier/shift/
  printers/tables), sales-period selector.
- Payment modal: 6 methods + **quick cash chips** (500/1000/2000/5000) +
  live change.
- Discount quick chips (5/10/15/20/25%).
- Shortcuts: Ctrl+K search, F1 new bill, F2 KOT, F4 charge.
- Plus v20 ka sab: shift gate/close/report, diagnostics, item+inventory
  create, server-side holds.

## Browser-tested (headless Chromium, asli render)
0 page errors ✓ item area 75%/7 cols ✓ 5 products with proper name+price ✓
calculator 2.5 apply ✓ bill table 4 columns ✓ discount 10% chip ✓ slip
preview + Print/WhatsApp ✓ customer create ✓ table create ✓ drawer 14 links
✓ system modal ✓ void logs ✓ payment 6 methods + cash chips + change ✓

---

# V22 — POS workflow rework (aapke 12 points)

1. **New Bill Screen**: "+ New Bill" par ek modal khulta hai — Dine In /
   Takeaway / Delivery. Dine In → usi screen par **table grid** (+ New Table
   wahin se). Takeaway → seedha POS. Delivery → **customer search list +
   New Customer** wahin se; select/create hote hi POS.
2. POS se **mode pills, table dropdown, customer dropdown hata diye**. Ab
   sirf read-only context chips (mode / table / customer) + "Change".
   Koi bhi bill shuru hone se pehle system type poochta hai; type select
   kiye baghair item add block hai.
3. **F1** = new bill screen · **F2** = Send to Kitchen · **F3** = Charge ·
   Ctrl+K = search.
4. Cart ki **poori row par kahin bhi click** karne se calculator khulta hai
   (sirf qty par nahi). +/- aur delete apna kaam karte hain.
5. **Tax DB se**: Cash % aur Card/Online % alag, per-branch — System &
   Settings modal se set hote hain (`pos-settings` / `pos-settings-save`).
   Payment method badalte hi charge modal mein tax auto switch hota hai.
6. **Send to Kitchen ab sirf DELTA bhejta/print karta hai** — 5 bottles ja
   chuki hon aur 2 aur add hon to KOT par sirf 2 aati hain. Print direct
   nikalti hai (preview ke baghair), KDS ko bhi wahi ticket.
7. **Charge modal**: khultay hi Received Amount par focus. Enter dabate hi
   payment complete → **bill khud print** → background mein naya bill
   (new-bill screen) chal jata hai.
8. **Payment error fix**: api() ab response se JSON nikaal kar parse karti
   hai (PHP warning/notice JSON se pehle aa jaye to bhi na toote), aur
   parse fail ho to saaf message deti hai.
9. Shift/account open na ho to POS **kuch nahi karta** — gate band nahi
   hota, item add bhi block.
10. **New Bill par mojooda bill khud HOLD ho jata hai** — alag Hold button
    dabana zaroori nahi (button phir bhi mojood hai).
11. Card par ab **item ka naam bara aur pehle**, category chhoti neeche.
12. Cart rows lambi (≈54px), delete **laal**, qty/rate/total ke columns
    chaure.

## Browser-tested (headless Chromium)
new-bill modal auto-open ✓ purane selectors 0 ✓ Dine In→9 tables→ctx ✓
Takeaway→seedha POS ✓ Delivery→customer list+create→ctx ✓ row-click
calculator ✓ tax 16% cash / 8% card switch ✓ KOT delta ✓ charge focus=rcv,
Enter→pay+print popup+new bill (0101→0102, cart clear) ✓ payment errors 0 ✓
F1 ✓ auto-hold (1)→(2) ✓ row 53.9px ✓ delete rgb(226,55,68) ✓ tax settings
save→17% reflect ✓

---

# V23 — POS refinements (9 points)

1. **ESC se new-bill popup band** hota hai + "Cancel (Esc)" button. Sirf
   Shift Opening gate band nahi hoti (wo lazmi hai).
2. **Item ka naam ab card par sabse upar aur bara** (13px bold). Category
   ab image par chhoti badge hai, is liye naam aur category gaddmad nahi.
3. Bill # / mode / table / customer / "+ New Bill (F1)" sab **upar status
   strip mein** shift ho gaye. Right panel ka pura hissa ab item table ko
   milta hai (~558px height).
4. **Har popup ESC se band** (topmost pehle).
5. **Hold Bill / Preview-Print / WhatsApp** ab bill ke neeche nahi —
   **Charge / Action popup ke andar** hain.
6. **Left drawer menu hata diya.** Logo par click: Admin/Manager →
   Dashboard, **Cashier → sirf apni Shift detail** (koi report nahi).
7. **Manager password** = Users & Access mein banaye gaye kisi bhi Admin /
   Owner / Manager user ka apna login password. Ab **server par verify**
   hota hai (`pos-verify-manager`), aur void log mein "approved by <naam>"
   record hota hai. Modal mein yeh hint bhi likha hai.
8. **KOT print ab simple**: restaurant detail nahi — sirf bara ORDER TYPE
   (TAKEAWAY/DINE IN + table), box mein **NEW ORDER / RUNNING ORDER**,
   bill no, time+date, aur bare font mein qty + item.
9. **Item picture change**: card par hover karte hi camera button. Modal
   mein — **Auto fetch by name** (item ke naam se photo), "Another photo",
   URL paste, ya **apni file upload** (max 300KB, data-URL). Remove ka bhi
   option. `menu_items.image_url` column + `migrate_menu_image.php`
   (entrypoint hooked).

## Browser-tested
ESC new-bill ✓ ESC charge ✓ drawer/menuBtn 0 ✓ logo button ✓ strip mein
bill+ctx+new-bill ✓ item names dikh rahe ✓ category badge ✓ picture modal
(auto/url/upload) + DB persist ✓ hold/preview/wa charge modal mein ✓ KOT
print = TAKEAWAY / RUNNING ORDER / bill / time / items (restaurant naam
nahi) ✓ manager password: ghalat reject, sahi accept + "by Ahmed Khan"
void log ✓ page errors 0 ✓

---

# V24 — POS fixes (8 points)

1. **Image auto-fetch fix**: `source.unsplash.com` band ho chuka hai, is
   liye hata diya. Ab **loremflickr** (free, no API key) se item ke naam par
   photo aati hai + **"Google Images"** button jo naam se search khol deta
   hai (right-click > Copy image address > URL box mein paste). Upload aur
   Remove pehle se hain.
2. **KOT NEW vs RUNNING fix**: pehle flag items ko "sent" mark karne ke BAAD
   compute hota tha, is liye har print RUNNING aata tha. Ab pehle check
   hota hai — pehla print **NEW ORDER**, usi bill ka agla print
   **RUNNING ORDER**.
3. **Item name invisible tha** (aur isi wajah se "Walk-in Customer"/"Change"
   chips bhi khali dikh rahe the) — text render ho raha tha magar white.
   Ab in sab par **explicit color `#15221b` (`!important` + inline style)**,
   CSS variables par bharosa nahi.
4. **Shift / Change Table**: strip ke "Table: X ⇆" chip par click karke bill
   ko doosre table par shift karein — bill wahi rehta hai, current table
   highlight hota hai, aur wahin se + New Table bhi.
5. Context chips ka text ab saaf dark (upar #3 ka hissa).
6. **Cart alignment**: RATE chhoti aur halki (11px grey), **TOTAL bara aur
   numayan** (14px bold), dono nowrap + vertically centered, description
   alag column mein wrap hoti hai — text ab upar-neeche nahi hota.
7. **Service Charge popup**: percent (3/5/8/10%) aur flat (50/100/200/300)
   dono ke quick buttons; type flat/percent select ho sakta hai.
8. **Sales Tax ab manually edit nahi hota** — uske Edit par **payment method
   picker** khulta hai (har method par uska rate dikhta hai). Method chunte
   hi tax rate khud lagta hai; rates System & Settings se aate hain.

## Browser-tested
item name visible rgb(21,34,27) ✓ ctx chips dark ✓ table shift Table 1 →
Table 21 ✓ rate 11px grey / total 14px bold ✓ svc quick 3/5/8/10% +
50/100/200/300 → PKR 100 ✓ tax edit → method picker → 5% Card ✓ KOT 1st
"NEW ORDER", 2nd "RUNNING ORDER" ✓ auto image url loremflickr ✓ Google
Images button ✓ page errors 0 ✓

---

# V25 — POS: new bill-row design, image search, PDF, holds, speed

1. **Naya cart row design** (aapke sketch ke mutabiq): pehli line item ka
   naam (bold), doosri line `1 x 560   sent:2`, right par bara **AMOUNT**,
   aur laal ×. Header ab DESCRIPTION | AMOUNT. **Qty ke +/- buttons hata
   diye** — quantity sirf row par click karke **calculator** se badalti hai.
2. **Image search ab server se**: naya `menu-image-search` endpoint
   (Openverse API, koi key nahi; na chale to keyword-photo fallback).
   Picture modal mein search box + **result grid** — thumbnail par click
   karke pick karein. Saath Google Images button, URL paste aur upload
   pehle ki tarah maujood.
3. **WhatsApp par asli PDF**: naya `bill-pdf` endpoint jo server par
   proper PDF invoice banata hai (`src/Services/Pdf.php` — dependency-free
   PDF writer, koi composer package nahi). WhatsApp modal mein PDF ka
   direct link + Copy + Open buttons — aap apni WhatsApp API se yehi link
   attachment ke tor par bhej sakte hain.
4. **Item-name masla**: Diagnostics modal mein ab **raw pos-boot sample**
   dikhta hai — pehle product ka `name` field, aur saaf verdict
   ("name field: OK" ya "KHALI!"). Is se ek nazar mein pata chal jayega ke
   masla data mein hai ya display mein. Card par naam par explicit color +
   inline style pehle se hai.
5. **Hold bills fix**: resume karne par hold record **delete NahI hota** —
   bill hold list mein maujood rehta hai jab tak payment complete na ho.
   Payment hote hi hold khud clear ho jata hai. Dobara hold karne par wahi
   record update hota hai (duplicate nahi bante).
6. **Speed**: `posBoot` har product par 3 alag queries chala raha tha (20
   items = 60 queries). Ab teeno windows **ek grouped query** se aati hain
   (~21ms). POS page par `db_boot.js` ki 2 extra synchronous XHRs bhi band
   (POS khud hydrate hota hai). **Load-to-first-item ab ~316ms.**

## Browser-tested
row "Playwright Tikka | 1 x 999 | PKR 999 | x" ✓ +/- removed ✓ row-click
calculator → 3 x 999 ✓ image grid 8 results + pick ✓ PDF link generate +
asli PDF (header %PDF-1.4, lines verified) ✓ hold resume → count wahi (4)
✓ payment → (3) ✓ diagnostics raw sample "name field: OK" ✓ 0 page errors ✓

---

# V26 — ASAL BUG MILA: image item name ko dhaank rahi thi

## Root cause (aapke screenshot ne pakra)
Product card ka thumb 70px tha, magar `.th img` ka `height:100%`
grid+`place-items:center` ki wajah se apply NahI hota tha — image apni
natural aspect par **118px** render hoti thi aur `.th` par `overflow`
visible tha. Nateeja: image thumb se bahar nikal kar **item name ke upar
chha jati thi** (isi liye naam "ghayab" tha) aur saath wale card par
overlap karti thi. Jab images load NahI hoti thin (fake URL) to naam nazar
aa jata tha — isi liye mere pehle test paas ho rahe the.

**Fix:** `.th{overflow:hidden!important;height:92px}` +
`.th img{position:absolute;inset:0;width:100%!important;height:100%!important;object-fit:cover}`
plus `.prod{overflow:hidden!important;isolation:isolate}` aur
`.pb{position:relative;z-index:2;background:#fff}` — image ab kabhi text
ke upar nahi aa sakti.

**Bonus fix:** `menu_items.image_url` schema mein `varchar(1000)` tha, is
liye uploaded (data-URL) images "Data too long" se fail hoti thin.
`migrate_menu_image.php` ab column ko **MEDIUMTEXT** mein widen karti hai
(idempotent, boot par khud chalti hai). Upload limit ab 1MB.

## Baqi
- **Image modal ab sirf 2 options**: "Find Photo" (search + suggested grid,
  "More" se aur photos) aur "Upload Image". URL box aur alag Google button
  hata diye.
  Server par: agar `GOOGLE_CSE_KEY` + `GOOGLE_CSE_CX` env set hon to
  **Google Custom Search Images** use hoti hai (response mein
  `source: google`), warna license-free suggested photos.
- **Cart row**: item ke aage **serial number** (dark badge 1,2,3...), aur
  `1 x 999` ab **bold + green pill** mein; "sent: 2" alag green pill,
  note amber pill.

## Browser-tested (asli bari images load karke)
thumb 92 / img 92 / card 194 ✓ overlapping cards: **0** ✓ item name
"Playwright Tikka" uncovered (elementFromPoint = .pn) ✓ modal tabs
[Find Photo, Upload Image], URL box gone, 8 results ✓ cart rows
"1 Playwright Tikka | 1 x 999 | PKR 999" + serial badges + qty pill
(12px/800/green) ✓ 0 page errors ✓

---

# V27 — Branding, Feature Assignment, Roles, Super Admin Dashboard, WhatsApp

## 1. Per-business branding (naam / logo / colors)
Super Admin > business row > **Branding**: display name, logo upload (1MB),
primary + accent color. Yeh sab **login screen aur poore software** par
lagta hai — naya `public/brand.js` har page par CSS variables aur brand
text/logo override karta hai (page ke markup ko chhue baghair).
Migration: tenants par `display_name, logo_url, brand_color, brand_accent`.

## 13. Feature assignment per business
Super Admin > **Features**: 36 modules ki list, jo select honge business ko
sirf wahi milenge (kuch select na karein = sab allowed).
Enforcement `Auth::canModule()` mein hai — **admin par bhi** lagta hai, is
liye API level par bhi block hota hai, sirf menu chhupana nahi.

## 2 / 3 / 4. Cashier roles
- `Auth::isManager()` (tenant admin ya Manager/Owner/Admin role).
- **Price change, item create, category create, picture change, POS
  settings — sirf Admin/Manager.** Cashier ko yeh buttons dikhte hi nahi
  aur server bhi 403 deta hai.
- Cashier ko POS par **"My Shift"** button milta hai: current shift ki
  opening/closing reconciliation report, print ke saath; shift band ho to
  aakhri closed shift ki report.

## 5 / 6. Super Admin ab proper software
Nav tabs: **Dashboard · Businesses · Create Business**.
Dashboard par 8 stat cards (businesses/active/suspended/branches/users/
revenue MTD/revenue total/renewals), **Renewals due (next 30 days)** table
(days-left color chips + inline Renew), aur **Recent payments**.

## 7. WhatsApp integration
Super Admin > **WhatsApp**: WA Engine ka Base URL + `x-api-key`,
**Test Connection** button (`/api/status` hit karta hai), aur **8 events**
select karne ke liye: bill_paid, order_received, order_ready,
order_dispatched, shift_closed, low_stock, subscription_expiry,
daily_summary. Settings tenant par save hoti hain.

## 10. Collation error (business list)
`Platform::listBusinesses()` aur dashboard queries ab **explicit
`COLLATE utf8mb4_unicode_ci`** ke saath join karti hain, aur
`migrate_collation.php` ab table-level ke saath **column-level** bhi fix
karti hai. Ab mixed-collation DB par bhi error nahi aayega.

## Tested (headless browser)
super admin: tabs ✓ 8 stat cards (revenue MTD PKR 30,000) ✓ renewals 2 rows
with day chips ✓ payments 3 ✓ features 36 modules → 5 assigned ✓ branding
saved ✓ WhatsApp 8 events → 2 enabled ✓ business list error-free ✓
client: login title "Royal Grill House", --brand #0a7d5a ✓ POS par bhi wahi
color (Add button rgb(10,125,90)) ✓ pos-boot `can.manage` ✓ 0 page errors ✓

---

# V28 — 502 FIX + Offline Download + QR Ordering + 80mm Print

## 0. 502 Bad Gateway (V27 ke baad) — FIXED
**Wajah:** entrypoint mein Apache SAB migrations ke BAAD start hota tha, aur
V27 ka naya column-level collation pass sainkron `ALTER` chalata hai — us
mein minutes lagte hain, Railway ka healthcheck timeout ho jata tha → 502.
**Fix (do taraf se):**
- Entrypoint restructure: **Apache pehle start** hota hai (background),
  bhaari migrations uske baad background mein chalti hain. App foran
  respond karta hai.
- `migrate_collation.php` ab **one-time** hai — nayi `migration_state`
  table mein marker rakhta hai. Dobara chalana ho to `FORCE_COLLATION=1`.

## 8 / 16. Download Offline Version
- Naya `offline-package` endpoint: **poora software ZIP** karke deta hai
  jis mein us business ka `config/offline.php` **already stamped** hota hai
  (tenant id, site id, site name, sync token, cloud URL). Sync token na ho
  to khud generate hota hai.
- ZIP mein `INSTALL_OFFLINE.bat` + `tools/install_offline.ps1`:
  PHP/DB check → local DB setup → **Desktop shortcut** (business ke naam se)
  → offline config activate. `OFFLINE_README.txt` Roman Urdu hidayat ke saath.
- POS > System & Settings > **Download Offline Version** (sirf Admin/Manager).
- Dockerfile mein `zip` extension add (ZipArchive ke liye lazmi).

## 14 / 15. QR table ordering (session-based)
- Nayi tables: `qr_sessions`, `qr_orders`.
- **Har scan par nayi session** banti hai jo default **90 minute** chalti hai
  (`QR_SESSION_MINUTES` se badlein). Expiry ka faisla **DB ke apne clock**
  par hota hai. Session expire/close ho to order **reject** — customer ghar
  ja kar order nahi kar sakta.
- Naya public page `qr.html` — mobile-first menu, categories, cart, live
  session timer, restaurant ki branding (naam/logo/color) ke saath.
- POS header par **QR Orders (n)** badge, har 20s auto-refresh. Modal mein
  pending orders — **Accept** karne par hi items cart mein aate hain
  (Reject bhi maujood). **Cashier ke confirm kiye baghair kuch proceed
  nahi hota.**
- POS > System > **Open QR Codes**: har table ka QR (print-ready grid) +
  Print All.

## 12. Print template + FBR QR
- POS > System > **Print Template**: paper **80mm (3") / 58mm**, FBR QR
  show/hide, custom footer text. Browser print par `@page size` isi ke
  mutabiq set hota hai.
- Final bill par **FBR QR placeholder** (invoice no + amount + tax payload)
  — asli FBR payload offline version se aayega.

## Tested (headless, do browser tabs: cashier + customer)
customer: QR page branding "Royal Grill House", Table 1, timer 89:58, menu
5 items, cart PKR 1,649, send → success screen ✓
cashier: badge (2) → pending row → Accept → cart mein "Gulab Jamun 2 x 250"
→ badge (1) ✓ · expired session → order reject ✓ · System modal: Offline /
QR / Template buttons ✓ template 80mm save ✓ 9 QR cards ✓ page errors 0 ✓

---

# V29 — Sync Security + Portable MariaDB + SEALED Offline Package

## 1. Per-tenant sync token + table whitelist (SECURITY)
Pehle sirf ek **global** `SYNC_TOKEN` tha: leaked hone par koi bhi kisi bhi
tenant ka data push/pull kar sakta tha — `platform_users` samet (yani super
admin account bana lena).
- `syncTenant()`: token ab `tenants.sync_token` se match hota hai aur
  request usi tenant par **lock** ho jati hai. Suspended business ka sync
  band. Local single-tenant node ke liye config token sirf non-cloud mode
  mein chalta hai.
- `syncTableAllowed()`: 48 safe tables ki **whitelist**. `users`, `roles`,
  `platform_users`, `tenants` waghera kabhi sync nahi ho sakte.
- `Sync::applyRows(..., $forceTenantId)`: har incoming row par `tenant_id`
  **force** hota hai — incoming value trust nahi ki jati.
- `Sync::changedRows()`: pull bhi tenant-scoped.

**Tested:** ghalat token → reject ✓ · `platform_users` push → 403 ✓ ·
doosre tenant ki row push → row apne hi tenant par force ("FORCED to my
tenant (SAFE)") ✓ · allowed table + apne tenant ka pull ✓

## 2. Portable MariaDB (customer PC par kuch install nahi)
`tools/resolve_mariadb.ps1` — MariaDB portable package ke andar
(`runtime/mariadb`), apna `data/mysql`, **port 3307**, sirf 127.0.0.1.
`vendor/mariadb.zip` rakh dein to internet ki bhi zaroorat nahi. PHP pehle
se auto-resolve hota tha. Installer ab: PHP → MariaDB → schema+migrations →
`bootstrap_offline.php` (tenant/site/roles/admin/defaults) → Desktop
shortcut. Uninstall = folder delete (koi service/registry nahi).

## 3. SEALED package (developer-proof)
`tools/build_offline_bundle.php`:
- Saara PHP (`src/`, `public/`, `scripts/`) + config **AES-256-GCM**
  encrypted `runtime/app.sealed` mein. Comments/whitespace stripped.
- Package mein **koi `src/` nahi, koi `config/` nahi** — sirf 4 chhote
  stubs + loader. **Sync token plaintext mein kahin nahi** (grep se verify).
- `sealed://` stream wrapper — `require`/autoload bina badle chalte hain
  (include paths build par rewrite hote hain).
- Key do hisson mein (`runtime/app.key` + loader), aur **HMAC integrity**:
  ek byte badalne par app chalna band → "Files tabdeel ki gayi hain".

**Tested (asli sealed package chala kar):**
readable php = sirf 5 stub files ✓ src/config = 0 ✓ token plaintext = nahi ✓
schema 93 tables ✓ 7 migrations + modules + tenant bootstrap + roles + admin ✓
HTTP: login 303 → POS v28 page → pos-boot (4 cats, 8 tables) ✓
shift open ✓ item create ✓ **bill finalize (netSales 900)** ✓ qr.html 200 ✓
tamper test: blob badla → app band, restore → 200 ✓

## Imandarana note
PHP interpreted hai — yeh sealing casual copying/editing rokti hai aur
tampering foran pakarti hai, lekin determined reverse-engineering ke khilaf
100% guarantee sirf ionCube/SourceGuardian jaisa commercial encoder deta hai.

## Regression
cloud: client login ✓ pos-boot ✓ qr-pending ✓ qr-tables ✓ pos-diagnostics ✓
pos-settings ✓ menu bridge ✓ store-state ✓ · local node ✓ ·
PHP lint 77 files clean ✓ · page guard 43 files OK ✓

---

# V30 — Offline package fixes (UI sealed, schema built-in, installer)

## 1. approved_ui customer ko nahi jati
Saari UI (HTML/JS/CSS jo `approved_ui/` mein hai) ab **seal ke andar** hai.
Router unhe sealed bundle se serve karta hai. Package mein `approved_ui/`
folder **hai hi nahi**.

## 2. docs/ (database schema) bhi built-in
`docs/*.sql` ab sealed bundle ka hissa hai; `install_schema.php` seal se
parhti hai. Package mein `docs/` folder nahi jata.
**Ab shipped folders sirf:** `public/` (browser-facing js/css/assets +
4 stub php), `runtime/`, `tools/` (4 scripts), `data/`, `storage/`,
`vendor/` — aur 2 .bat files.

## 3. INSTALL_OFFLINE.bat ka error
`config\offline.php nahi mili` — kyunki V29 se config sealed ho chuki hai
magar installer purani jagah dhoond raha tha. Ab installer
`runtime/app.sealed` check karta hai aur business ka naam
`runtime/app.info` se leta hai (sirf display name, koi secret nahi).

## 4. START_RESTAURANT.bat ka "database check failed"
Wo purana launcher installed MySQL maangta tha. Ab **`START_OFFLINE.bat`**
+ `tools/start_offline.ps1`: portable MariaDB start (port 3307) → private
PHP se local server (free port 8080+) → browser khud khul jata hai →
window band karne par DB bhi band. Desktop shortcut ab isi par point karta
hai. Purane launchers package se hata diye gaye.

## 5. Chupa hua bug pakra
Sealed bundle mein `__DIR__` = `sealed://...` hota hai, is liye router ka
`$static=__DIR__.'/'.$name` static files ko sealed path bana raha tha —
CSS/JS 302 aur **login-submit fail**. Ab build par yeh `APP_ROOT.'/public/'`
ban jata hai. (Isi tarah ek greedy path-rewrite rule bhi hataya gaya.)

## Tested (asli sealed package chala kar)
shipped: approved_ui 0, docs 0, src 0, config 0 ✓ · app.info sahi ✓
schema seal se 93 tables ✓ · 7 migrations + bootstrap + roles + admin ✓
login 303 → POS v28 page → shared.css 200 → pos-boot (4 cats, 8 tables) ✓
shift open ✓ item create ✓ **bill finalize netSales 800** ✓
pages: qr 200, menu 200, inventory 200, kds 200 ✓
cloud + local regression ✓ PHP lint clean ✓

---

# V31 — Offline installer: English, branding, PowerShell fix

## 1. PowerShell error fix (setup crash)
`The expression after '&' ... was not valid` — installer `php.exe` sirf
`$root\runtime` mein dhoondta tha. Aapke case mein PHP kisi purane folder
se resolve hua tha, is liye variable khali reh gaya aur `& $phpExe` fail
ho gaya. Ab teen fallbacks: (a) poore package mein `php.exe` search,
(b) resolver ki output se path, (c) system PATH. Na mile to saaf message.
Har setup step ka exit code bhi check hota hai — chup-chaap fail nahi hota.

## 2. Sab messages ENGLISH
`install_offline.ps1`, `start_offline.ps1`, `resolve_mariadb.ps1`,
`resolve_php.ps1`, dono `.bat` files aur `OFFLINE_README.txt` — sab English.
(Verify: leftover non-English strings = 0.)

## 3. Naming
Download filename ab **`smartpos_by_wabwar-<business-slug>.zip`**.
Product name **SmartPOS**, README mein suggested folder
`C:\smartpos_by_wabwar`.

## 4. Progress bars saaf
`$ProgressPreference='SilentlyContinue'` har script mein — wo lambi
`oooooo` waali PowerShell progress-bars khatam. Ab humari apni saaf
`Extracting....... done.` line aati hai.

## 5. Company info banner
Setup ke aakhir mein aur software start hone se pehle:
Business / Branch / Product / Company / Version / Contact number /
Website / Email. Values `runtime/app.info` se aati hain; server par env se
override ho sakti hain: `APP_PRODUCT, APP_COMPANY, APP_VERSION, APP_PHONE,
APP_WEBSITE, APP_EMAIL` (defaults: SmartPOS / Wabwar Software House /
1.0.0 / +92 300 0000000 / https://wabwar.com / support@wabwar.com).

## Tested
Content-Disposition: `smartpos_by_wabwar-royal-grill.zip` ✓
app.info mein poori company info ✓ shipped files: sirf 2 .bat + 6 folders ✓
PS scripts: braces balanced, non-English strings 0 ✓
sealed package: 93 tables + 11 setup steps ✓ login 303 → POS v28 →
pos-boot (4 cats, 8 tables) ✓ PHP lint clean ✓

---

# V32 — Offline setup: isolation, openssl fix, download progress

## 1. "Step failed" (11 steps) ka asli sabab
Installer PC par mojood **kisi bhi** php.exe ko utha leta tha. Aapke case
mein purane folder ka PHP mila jismein **openssl extension load nahi** thi —
sealed bundle openssl se decrypt hota hai, is liye har step crash hua
(boot.php line 38).

**Fix:**
- `resolve_php.ps1` poora naya: PHP ab **sirf is package ke andar**
  (`runtime\php`) rehta hai. System PHP (XAMPP/WAMP/Laragon/Workbench) ko
  jaan-boojh kar **ignore** kiya jata hai.
- php.ini har setup par khud likhi jati hai (openssl, mbstring, pdo_mysql,
  mysqli, curl, fileinfo, zip, gd) aur php.exe ke saath bhi copy hoti hai.
- Har PHP call ab `-c <php.ini>` ke saath chalti hai — koi doosri ini
  interfere nahi kar sakti.
- Setup se pehle **extension verification**: openssl/mbstring/pdo_mysql/zlib
  na mile to saaf message, aage nahi barhta.
- `boot.php` mein bhi guard: openssl na ho to
  "PHP 'openssl' extension is required but not enabled."

## 2. Mojooda MySQL / Workbench / XAMPP se koi taluq nahi
Database pehle se apne port **3307** par apne `data\mysql` folder ke saath
chalta tha; ab yeh dono scripts mein saaf likha bhi hai. System ka MySQL
service, uska data, uska port (3306) — sab bilkul chhua nahi jata.

## 3. Download progress (percentage)
Naya `tools/download_helper.ps1` — stream-based downloader jo live
percentage, MB/MB aur speed dikhata hai:
`PHP runtime : 42%  (38.1 / 90.4 MB at 4.2 MB/s)`
PHP aur database dono isi se download hote hain. `oooooo` waali bars
khatam.

## 4. Pehle se mojood file dobara download nahi hogi
- `vendor\php.zip` aur `vendor\mariadb.zip` — agar package ke saath aayen to
  **kuch download nahi hota**, seedha extract.
- Server par `vendor/` mein yeh files rakh dein to har generated package
  mein khud chali jayengi (`vendor/README.txt` mein links diye hain).
- Package ke andar PHP/DB pehle se extracted ho to setup unhe use karta hai,
  dobara download nahi karta.

## Tested
Package: `smartpos_by_wabwar-royal-grill.zip` ✓ tools mein 5 scripts ✓
shipped scripts mein non-English strings 0 ✓
sealed install: 93 tables + 11 steps sab pass ✓
login 303 → POS v28 → pos-boot (4 cats, 8 tables) ✓
openssl-missing simulation → saaf English message ✓ PHP lint clean ✓

---

# V33 — Launcher fix: "localhost refused to connect"

## Masla
Desktop shortcut chalane par browser khul jata tha magar server chal hi
nahi raha hota tha. Wajah: launcher **blind** tha — PHP process
`-WindowStyle Hidden` mein start hota tha, uski output kahin capture nahi
hoti thi, aur browser 2 second baad khol diya jata tha chahe server up ho
ya na ho. Agar PHP start hote hi mar jaye (extension, port, ya koi bhi
error) to user ko sirf "refused to connect" milta tha, wajah kabhi nahi.

## Fix (start_offline.ps1 poora naya)
1. **Pre-flight check**: server start karne se PEHLE app ko boot karke
   dekha jata hai (`SealedApp::boot` → APP_OK). Fail ho to asli PHP error
   screen par, saath tip: "delete runtime\php and run INSTALL_OFFLINE.bat".
2. **Free port properly**: ab `TcpListener` se test hota hai (pehle wala
   tareeqa kuch cases mein busy port ko free samajh leta tha).
3. **Server output logs mein**: `storage\logs\server.out.log` aur
   `server.err.log` — ab kuch chhupta nahi.
4. **Asli health check**: browser tabhi khulta hai jab server HTTP jawab
   de (20 second tak poll). Na chale to error lines screen par aur window
   khuli rehti hai (`Press Enter to close`) — foran band nahi hoti.
5. Desktop shortcut ab **normal window** mein khulta hai (pehle minimized
   tha, is liye errors nazar hi nahi aate the).
6. Band karte waqt PHP aur database dono properly stop hote hain.

## Naya: DIAGNOSE.bat
Ek click support tool — folder, Windows/PowerShell version, sealed package,
private PHP + version, **openssl/mbstring/pdo_mysql/zlib loaded ya nahi**,
application boot OK/FAILED, database port 3307 status, aur server logs ki
aakhri 10 lines. Yeh output support ko bhej dein to wajah foran mil jayegi.

## Tested
Package mein DIAGNOSE.bat + 6 tools ✓ pre-flight "APP_OK" ✓
health-check: server ~0.7s mein HTTP 200 par detect ✓ PHP lint clean ✓

---

# V34 — Offline package ab business ke apne users + data ke saath

## Masla
Offline version mein sirf demo admin (`admin@urbanspoon.local`) banta tha.
Business ke apne users local DB mein jate hi nahi the, is liye client
apne asli credentials se login NahI kar sakta tha — aur software khali
khulta tha (koi menu, tables, customers nahi).

## Fix — FIRST-RUN SNAPSHOT
Package banate waqt us business ka apna data bhi **sealed bundle ke andar**
chala jata hai (plaintext kahin nahi):
- **users + roles + user_roles + role_modules** — wahi credentials, wahi
  permissions offline chalte hain (password hashes as-is, is liye same
  password kaam karta hai).
- **Master data**: menu_categories, menu_items, menu_item_variants,
  printers + routes, inventory_categories/items, stock_balances,
  stock_locations, payment_methods, units, floors, dining_tables,
  customers + addresses, suppliers + items, recipes + ingredients,
  expense_categories.
- Child rows apne parents se filter hote hain (orphan rows nahi jatin).

`bootstrap_offline.php` pehle run par yeh sab import karta hai —
**idempotent**: jo row pehle se ho wo chhoR di jati hai, ek row fail ho to
baqi chalti rehti hain.

`ensure_default_admin.php` mein guard: agar business ke asli users mojood
hon to demo admin **banta hi nahi**
("DEFAULT_ADMIN_SKIPPED (business users already present)").

## Tested (asli sealed package chala kar)
snapshot: **imported=52** ✓ demo admin skipped ✓
offline login `owner@royalgrill.pk` → **303 → index.html** ✓
pos-boot: cashier "Ahmed Khan / Admin", brand "Royal Grill House",
5 products, 5 categories, 9 tables, 3 customers ✓
local + cloud regression ✓ PHP lint clean ✓

---

# V35 — Offline login dropdown + Tablet/Mobile QR pairing

## 1. Login: user ab DROPDOWN se
Offline version mein cashier ko naam type nahi karna parta — **"Select user"**
dropdown aa jata hai (naam + role). Naya `users-list` endpoint sirf LOCAL
node par kaam karta hai; **cloud par 403 "Not available"** (verify kiya) —
is liye kisi doosre business ke users kabhi expose nahi hote.
Ek hi user ho to wo khud select ho jata hai; password par focus chala jata hai.

## 2. Business isolation (already, ab confirm)
Package ka snapshot `tenant_id`/`site_id` se scope hota hai — jis restaurant
ne download kiya sirf uska data jata hai. Har business apna alag package,
apna sync token, apna sealed config.

## 3. Tablet / Mobile pairing — QR se (aapki suggestion, implement ho gayi)
Naya `paired_devices` table + `pair.html` page.
- POS > System > **Connect Tablet / Mobile** > "Waiter Tablet" ya
  "Kitchen Display" > QR screen par aa jata hai.
- Server apne **LAN IPs** khud detect karke QR banata hai:
  `http://192.168.x.x:8080/pair.html?t=<token>`
- Tablet usi WiFi par QR scan kare → session ban jati hai → seedha
  Order Taker khul jata hai. **Internet ki zaroorat nahi.**
- Token **15 minute** valid (env `PAIR_TOKEN_MINUTES`), one-tap "New Code".
- **Connected Devices** list + **Revoke** — kisi bhi device ka access foran
  band.
- Roles: WAITER → Order Taker, KDS → Kitchen Display, CASHIER → POS,
  MANAGER → Dashboard.

## Tested
cloud `users-list` → 403 (isolation) ✓ offline `users-list` → mode local,
"Ahmed Khan | owner | Admin" ✓
pairing: token + LAN URL bana ✓ tablet ne scan kiya → claim ok
(role WAITER, redirect Order Taker) ✓ tablet ne Order Taker page 200 aur
pos-boot (5 products, user Ahmed Khan) liya ✓
offline install: 12 steps, snapshot import, demo admin skipped ✓
local + cloud regression ✓ PHP lint clean ✓

---

# V36 — Offline shifts, per-user modules, live sync, stability

## 1. Offline mein "Download Offline Version" nahi
Button ab sirf cloud portal par dikhta hai (`can.offline_download`), aur
server bhi offline node par 403 deta hai — verify kiya:
"Offline version sirf online portal se download hoti hai".

## 2. Har counter ki apni shift
`cashier_shifts` mein `counter_name` add. Ab:
- Shift **user-scoped** hai — `shift-current` sirf apni shift deta hai,
  saath "open_shifts" list (kaun kaun se counter chal rahe hain).
- Ek user ki ek waqt mein **ek hi shift**: dobara open par
  "Aap ki shift S-... pehle se open hai."
- **Ek counter par ek hi cashier**: doosre ne wahi counter chuna to
  "Counter 1 par <naam> ki shift (S-...) open hai."
- Shift gate mein ab **Counter** field bhi hai.

## 3. Cash clear kiye baghair agli opening nahi
`cash_cleared` + `cleared_amount` columns. Close modal mein checkbox
"Cash drawer clear kar diya". Clear na ho to agli shift open par block:
"Pichli shift S-... ka cash clear nahi hua" — aur wahin **"Clear Cash Now"**
button de diya jata hai (naya `shift-clear-cash` endpoint).

## 4. Shift transfer + handover record
Naya `shift_handovers` table aur `shift-transfer` endpoint. Close modal mein
**Transfer Shift** button: agla cashier chunein, counted cash + handed cash
dalein → purani shift close (cash cleared, "Handover to <naam>"), **nayi
shift handed cash ke saath khud open**, aur handover ka poora record.
Test: Ahmed → Bilal, expected 5000 / counted 5200 / **variance +200** /
handed 5200 → nayi shift S-260826-80F2 ✓ record bhi bana ✓

## 5. Sirf assigned modules dikhte hain
`pos-boot` ab `can.modules` bhejta hai aur naya `my-modules` endpoint bhi.
POS par: menu module na ho to "+ New Item" gayab, void na ho to Void Logs,
settings na ho to System. Server-side enforcement `Auth::canModule()` mein
pehle se thi (feature flags + role) — ab UI bhi match karti hai.

## 6. Live sync — ab sach mein hota hai
**Wajah:** `users` (aur bohat si tables) push list mein thin hi nahi, aur
offline launcher koi sync loop start nahi karta tha.
- Server whitelist mein ab `users, user_roles, roles, role_modules,
  employee_profiles, cashier_shifts, shift_handovers, paired_devices`
  waghera shamil (tenant-lock upar lagta hai; **platform_users abhi bhi
  blocked** — verify kiya).
- Package ke push list mein ab **46 tables** (staff, sales, catalogue,
  inventory, people, setup).
- **Auto-sync loop** ab launcher se background mein chalta hai
  (`sync_loop.php`, har **2 minute**), log `storage\logs\sync.log`.
  Software band karne par loop bhi band.
Test: offline-banaya user `sync-push` se cloud par pohancha ✓
`platform_users` push → 403 ✓

## 7. Stability sweep
79 PHP files lint clean · saari standalone JS clean · **44 pages ke inline
scripts** parse OK · page-guard OK · saari PowerShell scripts balanced ·
14 cloud endpoints OK · offline package: 13 setup steps, snapshot import,
demo admin skip, login 303, dropdown mein 3 users.

---

# V37 — SYNC FIX: offline ka data ab live par aata hai

## Do asli bugs (dono mile aur fix hue)

**Bug 1 — config key mismatch.** Offline package `sync.endpoint` likhta tha,
magar sync engine `sync.cloud_api_url` parhta hai. Is liye
`Sync::enabled()` hamesha FALSE rehta tha aur har sync
**"Local-only mode (no cloud URL set)"** par ruk jati thi — sync kabhi
chali hi nahi.
Fix: package ab `cloud_api_url` likhta hai, aur engine dono keys accept
karta hai (`endpoint` ya `app.cloud_url` se bhi resolve kar leta hai) —
is liye purane installs bhi theek ho jate hain.

**Bug 2 — double /api.php.** URL `.../api.php` + `/api.php?action=...`
ban jata tha → cloud **HTTP 302** deta tha. Ab endpoint builder dono
suraton (base URL ya poora api.php) ko sahi handle karta hai.

## Behtar error messages
- `Sync::statusReason()` — saaf wajah: sync off / cloud URL missing /
  token missing.
- `sync-status` ab role, cloud_url aur reason bhi deta hai.
- Cloud par "Sync now" dabane par ab saaf message: "Yeh cloud server hai —
  sync offline node se chalti hai."
- HTTP error mein ab server ka jawab bhi shamil hota hai (sirf code nahi).
- Offline Sync page ab pushed/pulled rows dikhata hai, "Local-only" nahi.

## Live par bhi user dropdown
Cloud login par bhi ab **dropdown** aata hai — lekin sirf **usi business ke
users** jiska client link (`?b=slug`) use hua ho. Bina slug ke list nahi
milti ("Business link ke baghair user list available nahi").
Test: royal-grill link → 3 users · grill-two link → sirf uska 1 user ·
bina slug → 403.

## PROOF (end-to-end)
Offline package install → offline mein item **"LIVE-SYNC-PROOF" (777)**
banaya → Sync now → pushed: users 3, roles 9, menu_categories 5,
menu_items 6 ... → **cloud DB mein row mojood** aur **cloud POS grid mein
item nazar aa raha hai** ✓
Auto-sync loop har 2 minute background mein yehi karta hai.

## Regression
PHP lint clean · 44 pages OK · local node OK · cloud endpoints OK

---

# V38 — Live sync indicator + unique package name

## 1. POS par LIVE sync status (strip mein)
Naya `sync-state` endpoint + POS strip par chip jo har 30 second update
hota hai:
- `☁✓ Synced · just now` — sab upload ho chuka (green)
- `↻ 12 to sync` — itni rows abhi baqi (amber, **click = foran sync**)
- `☁ Offline · 5 pending` — cloud abhi reachable nahi
- `⚠ Sync off` — configuration ka masla (tooltip mein wajah)
Tooltip mein last sync ka waqt aur cloud URL bhi.

**Auto-sync ab browser se bhi**: POS khula ho to har **2 minute** khud sync
chalata hai — background loop chale ya na chale, data upar jata rahega.

## 2. System modal mein poori tafseel
System & Settings > **Cloud Sync**: Mode (Offline node / Cloud server),
Sync enabled, Cloud online/not reachable, Last sync, **Waiting to upload
(rows)**, Cloud URL, aur koi error ho to wo bhi. Saath **Sync Now** button.

## 3. Package ka naam ab unique
Pehle har download ka naam aik jaisa tha, is liye Windows
`smartpos_by_wabwar-a (1)` bana deta tha — aur us space/bracket se setup
toot-ta tha. Ab: **`SmartPOS_<slug>_YYYYMMDD_HHMM.zip`**
(misal `SmartPOS_royalgrill_20260826_1311.zip`). README mein folder
suggestion bhi `C:\SmartPOS`.

## Tested
sync-state lifecycle: pending 37 → item banaya → 38 → sync → **0 pending**,
cloud par item mojood ✓
Browser: login dropdown (3 users) ✓ shift gate counter field ✓
chip "↻ 1 to sync" → sync → **"☁✓ Synced · just now"**, toast
"Sync complete - 2 rows uploaded" ✓
System > Cloud Sync: Mode "Offline node", Sync "Enabled", Cloud "Online",
Last sync "just now" ✓ page errors 0 ✓ PHP lint clean ✓

---

# V39 — "Offline · 70 pending" ka sabab + click par poora diagnosis

## Asli wajah (jo aap ke case mein sab se ziada mumkin hai)
`cloudOnline()` sirf true/false deta tha aur asli error nigal jata tha.
Backend jaanch se **teen** masle nikle:

1. **HTTPS ke liye CA bundle hi nahi tha.** Windows par PHP ke saath koi
   certificate bundle nahi aata. Railway `https://` par hai, is liye cURL
   certificate verify na kar paata aur "Cloud unreachable" de deta —
   chip par sirf "Offline" nazar aata.
   **Fix:** package ke saath ab `runtime/cacert.pem` jata hai (224 KB),
   `php.ini` mein `curl.cainfo` + `openssl.cafile` set hoti hain, aur
   `httpPost()` khud bhi CAINFO lagata hai.

2. **Har 30 second live network probe** POS ko block kar deta tha (PHP ka
   built-in server single-threaded hai). **Fix:** probe ka natija ab
   60 second cache hota hai, aur sync endpoints `session_write_close()`
   kar dete hain taake baqi POS calls na rukein.

3. **Sync calls synchronous XHR thin** — network slow ho to poora POS
   freeze. **Fix:** ab async hain, cashier billing karta rahega.

## Chip par click = poora diagnosis
Chip ab `⚠ Offline · 38 pending — click to check` dikhata hai (tooltip
mein asli error). Click par **step-by-step report**:
Configuration · Cloud URL · Sync token · HTTP client · SSL certificates ·
DNS lookup · Connection · Cloud response · Token accepted
Har step par ✅/❌ aur asli detail, phir **"Kya karna hai"** box jo pehle
fail hone wale step ke hisab se hal batata hai (firewall, DNS, SSL,
token, package dobara download). Saath **Copy Report** (support ko bhejne
ke liye) aur **Retry Sync**.

## Ye bugs bhi mile aur theek hue
- Chip ka click handler kabhi bind hi nahi hota tha (galat jagah lagi thi)
  — ab `renderStrip()` mein har render par bind hota hai.
- Probe cache kabhi hit nahi karta tha: `watermark` NOT NULL ki wajah se
  insert fail, aur microsecond timestamp par `strtotime()` fail. Ab age
  SQL (`TIMESTAMPDIFF`) se aati hai.
- Diagnose mein do false alarms: IP address par "DNS fail", aur cURL na
  hone par "FAIL" (jabke fallback chalta hai) — dono theek.

## Tested
Healthy: saare 8 steps ✅, cloudOnline TRUE
Unreachable cloud: chip "⚠ Offline · 38 pending", click → modal **0.06s**
mein khula (POS freeze nahi hua) → ❌ Connection par ruka aur firewall
wala hal dikhaya ✓
Wrong token: ❌ "Token accepted — Rejected: Invalid sync token" ✓
Probe cache: call 1 live, call 2-3 cached ✓ PHP lint clean · 44 pages OK

---

# V40 — COMPLETE SYNC: har table, har record (asli sabab mil gaya)

## Jar par masla: 23 tables kabhi sync hoti hi nahi thin
Sync engine watermark ke liye `updated_at` (ya `created_at`) column parhta
hai. Jis table mein yeh column NahI, `tsColumn()` `null` de deta tha aur wo
table **khamoshi se skip** ho jati thi — na error, na warning.

Audit se nikla ke **23 tables ka koi timestamp column hi nahi tha**, jin
mein sab se aham **`payments`** hai. Yani bills to upar chale jate the
magar unki payments nahi — isi liye aap ka local dashboard 30,508 aur live
14,195 dikha raha tha. Saath in ka bhi yehi haal tha:
`stock_transactions`, `stock_transaction_lines`, `stock_adjustments`,
`kitchen_tickets`, `kitchen_ticket_items`, `user_roles`, `role_modules`,
`customer_addresses`, `recipe_ingredients`, `supplier_items`,
`payment_methods`, `floors`, `units`, `stock_locations`,
`expense_categories`, `goods_receipt_items`, `fiscal_invoices`,
`notification_queue`, `delivery_orders`, `qr_sessions`,
`menu_category_printer_routes`, `user_form_permissions`.

**Fix:** nayi `migrate_sync_columns.php` har syncable table par
`updated_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE
CURRENT_TIMESTAMP(6)` add karti hai, purani rows ko `created_at` se
backfill karti hai, aur us table ka watermark reset kar deti hai taake
poora purana data ek dafa upar chala jaye. Cloud par chalti hai to
**34 tables** theek hui. Boot par khud chalti hai (cloud + offline dono).

## Ab har cheez sync hoti hai (56 tables)
Push list aur server whitelist dono mukammal: sales (orders, order_items,
payments, voids, kitchen tickets, shifts, handovers, QR orders, fiscal
invoices), catalogue (menu, variants, recipes, printer routes), inventory
(items, transactions, lines, balances, adjustments, GRN, PO, units,
locations), people & setup (customers, addresses, suppliers, expenses,
reservations, riders, delivery, promotions, printers, floors, tables,
payment methods, devices), aur staff (users, roles, role_modules,
permissions). `platform_users`/`tenants` phir bhi block hain.

## Sync ab admin dashboard par (POS se hata diya)
POS strip se chip nikal di — cashier ka is se koi taluq nahi.
**Dashboard par poora "Cloud Synchronization" card**: status pill (up to
date / N waiting / cloud offline / sync off), last sync, waiting rows,
cloud online, aur error. **Sync now** aur **Check** (step-by-step
diagnosis + "What to do") buttons. Background par 2 minute par khud chalta
hai — POS par bhi wahi loop chalta rehta hai (bina kuch dikhaye).

## PROOF
Offline node par 3 bills kaate (PKR 6,000) → Sync → **150 rows pushed**:
orders 3, order_items 3, **payments 3**, kitchen_tickets 3,
cashier_shifts 1, menu_items 7, users 3 → pending **0**.
Cloud DB par teeno bills **apni payments ke saath** mojood ✓
(Cloud ka total is se zyada isliye hai ke us par pehle se online kaate
gaye 5 bills bhi hain — wo local par nahi aate; transactions upar jate
hain, neeche master data aati hai.)

PHP lint clean · 44 pages OK · dashboard JS clean

---

# V41 — TWO-WAY sync: har table, dono taraf

## Aap ke dono sawal
**1. Users sync hote hain?** Haan — ab dono taraf. Test: offline par banaya
"Offline Cashier" cloud ki list mein aa gaya; cloud par banaya "Cloud
Manager" local login dropdown mein aa gaya. `users`, `user_roles`, `roles`,
`role_modules`, `user_form_permissions`, `employee_profiles` — sab.

**2. Sync sirf local se online tha?** Bilkul — yahi masla tha. Pehle
**pull sirf 12 master tables** ka tha (menu, items, suppliers waghera).
Cloud par bana koi order, shift, user ya stock entry local par **kabhi nahi
aata tha**. Ab **push 56 = pull 56** — dono lists bilkul barabar, koi table
push-only nahi.

## Do-tarfa sync mehfooz banane ke liye 3 cheezein
1. **Last-write-wins**: pull par agar local row zyada nayi hai to use
   overwrite NahI kiya jata — abhi kaata gaya bill purani cloud copy se
   mit nahi sakta.
2. **Per-row error isolation**: ek row ka masla (misal duplicate bill
   number) poori batch ko nahi rokta; baqi rows chalti rehti hain aur
   error diagnostics mein record hota hai. Pehle poora transaction fail
   ho jata tha.
3. **Branch scope**: pull request ab `site_id` bhejti hai aur server usi
   branch ka data deta hai — multi-branch business mein ek branch ko
   doosri branch ka transactional data nahi milta (tenant lock pehle se
   tha).

## PROOF
CLOUD -> LOCAL: cloud par item banaya -> offline sync -> **300 rows pulled**
-> item local POS grid mein mojood ✓
LOCAL -> CLOUD: offline par user banaya -> sync -> users 2, user_roles 1
pushed -> cloud user list mein mojood ✓
CLOUD -> LOCAL (user): cloud par manager banaya -> sync -> users 1 pulled
-> local login dropdown mein mojood ✓
pending after sync: 0 · cloud online: true

PHP lint clean · 44 pages OK

---

# V42 — Synchronization LOG (dono taraf)

Pehle sirf `sync_state` mein har table ka cumulative total tha — yeh nahi
pata chalta tha ke **kab** sync hua, us pass mein **kaun si tables** gayin
aur **kitni rows**. Cloud par to koi record hi nahi tha.

## Do nayi tables
- **`sync_runs`** (branch computer): har sync pass ka ek row — kab shuru,
  kitni der (ms), kis ne chalaya (auto/manual), kitni rows upar/neeche,
  kitni tables, status (OK / PARTIAL / ERROR), aur error text.
  `detail_json` mein har table ka direction + rows.
- **`sync_activity`** (cloud + branch): har table ke har transfer ka row —
  direction, rows, kis branch/IP se, kab.

Dono khud saaf hoti hain: **60 din** se purani entries hat jati hain.

## Dashboard par "View log" button
**Branch computer par:** aakhri 20 sync passes — waqt, trigger, duration,
status pill, `⬆ upar / ⬇ neeche` rows, aur har pass ke andar **har table ka
chip** (misal `⬆ orders 15`, `⬆ payments 10`, `⬇ menu_items 1`). Kuch
transfer na hua ho to "Nothing to transfer - already up to date."

**Cloud par:** 24 ghante ka summary (transfers, rows received, last
activity) aur recent transfers ki list — kaun si table, kitni rows, **kis
branch IP se**.

## PROOF (asli output)
Branch: `09:21:02 manual OK 367ms up=277 down=374` — role_modules 207,
menu_items 8, users 5, orders 15, payments 10, kitchen_tickets 17 ...
(47 tables)
Cloud: `last 24h: transfers=61 rows=752` — PUSH cashier_shifts 6,
qr_sessions 4, stock_transaction_lines 2, ui_records 8 ... from 127.0.0.1

## Behtar diagnostics
`sync-run` ab `run_id`, `skipped_rows` (last-write-wins se chhoRi gayi
rows) aur `row_errors` (per-table) bhi deta hai. Agar kuch rows fail hon to
status **PARTIAL** hota hai — pehle poora pass "OK" dikha deta tha.

PHP lint clean · 44 pages OK

---

# V43 — Sync na ho to WAJAH bhi log mein

## Sab se bara masla: ek table fail = poora sync khamosh maut
`push()` / `pull()` mein koi try/catch nahi tha. Agar ek table par masla
aata (network, token, duplicate row) to **loop wahin ruk jata** —
baqi 55 tables kabhi try hi nahi hotin, aur log mein sirf ek generic error
aata tha. Kaun si table ruki, kyun ruki — kuch pata nahi chalta tha.

**Fix:** har table apne try/catch mein hai. Ek table ka masla baqi tables
ko nahi rokta, us table ka `sync_state` `ERROR` ho jata hai (wajah ke
saath), aur log mein wo table alag se **❌ Not synced** ke neeche naam +
wajah ke saath aati hai.

## Fatal errors par foran ruk jao (log ka shor khatam)
Galat token par pehle **74 ek jaisi errors** log mein bhar jati thin.
Ab network down / 401 / 403 / suspended / 5xx ko **fatal** samjha jata hai —
sync foran rukti hai, ek saaf wajah ke saath. (Sirf 2 entries, 74 nahi.)

## Aam aadmi ki zaban mein wajah
`friendlyError()` technical error ko samajhne laayak jumle mein badalta
hai, aur raw error bracket mein saath rehta hai:
- invalid token → "The cloud rejected this installation (invalid sync
  token). Download the offline package again from the portal."
- connection refused → "Cannot reach the cloud server. Check the internet;
  Windows Firewall or antivirus may be blocking php.exe."
- certificate → "HTTPS certificate could not be verified. Download a fresh
  package..."
- DNS / timeout / 5xx / duplicate — sab ke apne jumle.

## Dashboard log ab failures dikhata hai
Har run ke andar do hisse: kamyab tables ke green/blue chips, aur uske
neeche laal **"❌ Not synced (n)"** — har nakaam table ka naam aur wajah.
Cloud log mein bhi FAILED entries laal aur note ke saath.

## Tested (asli sealed package)
WRONG TOKEN → ok=false, status=**ERROR**, reason: "The cloud rejected this
installation (invalid sync token)...", failed tables **2** (74 nahi) ✓
NETWORK DOWN → status=**ERROR**, reason: "Cannot reach the cloud server..." ✓
HEALTHY → status=**OK**, up=347 down=444, failed tables **0** ✓

PHP lint clean · 44 pages OK · dashboard JS clean

---

# V44 — Sync log ka button ab waqai kaam karta hai

## Bug: panel ka JS kabhi chalta hi nahi tha
Cloud Synchronization ka saara JavaScript ghalti se
`<script src="shared_store.js">` **tag ke andar** chala gaya tha. Browser
aise script ke andar likha code **kabhi execute nahi karta** — is liye card
hamesha "checking..." par atka rehta tha aur **View log / Check / Sync now
teeno buttons chhupe rehte the**. (Dono jagah — cloud aur branch.)
Fix: JS page ke asli inline script block mein shift kar diya, aur
`shared_store.js` tag ko sahi band kiya.

Saath: agar `sync-state` fail ho jaye tab bhi card **ab dikhta hai**
(pehle chhupa reh jata tha) — wajah card par likhi aati hai.

## Log kahan se milta hai
**Dashboard (index.html) > "Cloud Synchronization" card > "View log"**

- **Online portal par**: 24 ghante ka summary (transfers, rows received,
  last activity) aur har transfer — table, rows, direction, aur **kis
  branch IP se**.
- **Offline/branch computer par**: aakhri 20 sync passes — waqt, trigger
  (auto/manual), duration, status, `⬆ upar / ⬇ neeche` rows, har table ka
  chip, aur nakaam tables **❌ Not synced** ke neeche wajah ke saath.

Database mein bhi maujood hai: `sync_runs` (har pass) aur `sync_activity`
(har table ka transfer) — dono 60 din baad khud saaf.

## Tested (asli browser)
Cloud dashboard: card visible ✓ pill "cloud server" ✓ button "View log" ✓
log: **transfers 108, rows received 1543**, last activity 9:24:57,
har entry table + rows + direction + IP ke saath ✓ page errors 0 ✓

PHP lint clean · 44 pages OK

---

# V45 — Cloud Synchronization card: kis ko dikhe, kis ko nahi

## Aap ka sawal: yeh sirf offline version mein hai ya online mein bhi?
Dono mein — magar maqsad alag:

**Offline (branch computer):** poora control —
status pill (up to date / N waiting / cloud offline / sync off), last sync,
waiting rows, cloud online, aur **Sync now · Check · View log** teeno.

**Online portal:** monitoring window — pill par
**"1 branch computer"** (kitne nodes sync kar rahe hain), last activity ka
waqt, aur **View log** (24h summary + har transfer: table, rows,
direction, branch IP). Sync/Check buttons chhupe hain kyunki cloud khud
sync nahi chalata — branch computers isay data bhejte hain.

## Behtari: khali card ab nahi dikhta
Jis business ne offline version **istemal hi nahi kiya**, us ke online
dashboard par ab yeh card **bilkul nahi aata** (pehle "cloud server" likha
khali card aata tha — bekar shor). Faisla asli data par hota hai:
`sync_activity` mein us tenant ki koi entry hai ya nahi. Pehli sync hote
hi card khud zahir ho jata hai.

## Tested (asli browser, do businesses)
royal-grill (offline node maujood): card **visible**, pill
"1 branch computer", "Last activity: 8/26/2026, 9:24:57 AM",
button [View log] ✓
grill-two (koi offline node nahi): card **hidden** ✓ · page errors 0

PHP lint clean · 44 pages OK

---

# V46 — Cloud card: card wapis, aur header tile ab sach bolta hai

## Meri pichli tabdeeli ghalat thi
V45 mein maine card ko **chhupa** diya tha jab koi offline node na ho.
Nateeja: aap ko lagta hai kuch toot gaya. Yeh sahi faisla nahi tha —
card ab **hamesha dikhta hai**, magar saaf empty state ke saath:

  pill : "no branch computer yet"
  text : "No offline installation has connected yet. Install the offline
          version on a branch computer and its activity will appear here
          automatically."
  boxes: Branch computers 0 · Last activity never ·
         What to do: POS → System & Settings → Download Offline Version

**View log** button bhi hamesha maujood; khali ho to amber box saaf batata
hai ke log kab bharega.

## Ek chhupa hua jhoot bhi pakra
Dashboard ke header ka **"Cloud sync — Synced — Just now"** tile
**hardcoded** tha. Chahe sync band ho, cloud reachable na ho, ya offline
version istemal hi na kiya gaya ho — hamesha green "Synced" likha aata
tha. Ab asli haalat dikhata hai:

  Online portal : "1 branch" + last activity  /  "Not in use" (amber)
  Branch PC     : "Synced" / "Pending N rows" / "Offline" / "Off"
                  (green / amber / red)

## Card par ab asli numbers (online portal)
Branch computers · Last activity · Received (24h) rows · Transfers (24h) —
`sync_activity` se seedhe.

## Tested (asli browser, dono halat)
Branch computer maujood: tile "1 branch / 9:24:57 AM", pill
"1 branch computer", boxes [1 · 9:24:57 AM · 1543 rows · 108] ✓
Koi branch computer nahi (aap ka mojooda halat): tile "Not in use /
No branch computer", card **visible**, pill "no branch computer yet",
boxes [0 · never · POS → System & Settings → Download Offline Version],
View log khulta hai aur saaf batata hai ✓ page errors 0

PHP lint clean · 44 pages OK

---

# V47 — DATA LOSS FIX: bill numbers takrana + khamosh merge

Aap ke do dashboards (local 31,146 / cloud 14,195) ka poora tajziya kiya.
**Do alag masle** the:

## Masla 1 — Cloud par purana build
Aap ka local log `⬆144 rows OK` dikha raha tha (users 2, payments 6,
kitchen_tickets 6, floors 2 ...) magar cloud "no branch computer yet".
Wajah: Railway par **V42 se purana** build hai — us mein `sync_activity`
table aur logging hai hi nahi. Data ja raha tha, cloud record nahi kar raha
tha. **Hal: cloud par yeh version deploy karein** (ye aap ko karna hai).

## Masla 2 — Bill numbers takra kar DATA MITA rahe the (asli sabab)
`orders` par unique key hai `(site_id, business_date, bill_no)`. Offline
node aur online POS dono aaj ke din `0001, 0002 ...` banate hain. Cloud par
`INSERT ... ON DUPLICATE KEY UPDATE` chalti thi, to doosre node ka bill
**mojooda bill ko overwrite** kar deta tha — do bills mil kar ek, raqam
badal jati, aur server phir bhi `applied:1` keh deta tha.
Sabit kiya:
  cloud bill 7777 = 10,000 + offline bill 7777 = 20,000
  -> cloud par EK row bachi, raqam 20,000 (10,000 wala bill gum)

### Teen fix
1. **Node-scoped bill numbers**: har offline package ko apna `node_code`
   milta hai (L1, L2, ...) aur uske bills `L2-0001` bante hain. Cloud ke
   bills `0001` hi rehte hain. Takra hi nahi sakte. Counter bhi sirf apne
   prefix wale bills ginta hai.
2. **Server ab khamoshi se merge nahi karta**: wahi `id` mile to UPDATE,
   warna INSERT; koi doosri unique takra jaye to row **reject** + conflict
   detail wapas (`applied`, `sent`, `conflicts`, `conflict_detail`).
3. **Push ab applied count verify karta hai**: cloud ne jitni rows li usse
   kam apply kin to **watermark aage nahi barhta** (row dobara koshish
   hogi) aur log mein PARTIAL + wajah aati hai. Pehle wo rows hamesha ke
   liye gum ho jati thin.

## Tested
Conflict: row A (10,000) mehfooz, row B reject —
`applied:0, conflicts:1, "Duplicate entry ... for key uq_order_site_bill_date"` ✓
E2E: offline bills **L2-0001, L2-0002**, cloud bill **9004**, sync ok,
orders 2 + payments 2 pushed, table errors **none**, cloud par tamam bills
saath saath mojood ✓ local node regression ✓

PHP lint clean · 44 pages OK

---

# V48 — Sync timeout, atka hua sync, aur khali cloud log

Aap ke dono screenshots ka tajziya: teen alag masle mile, teeno fix.

## 1. Timeout — sync 60-90 second le raha tha
Wajah: har table ke liye **alag HTTP request** jati thi. 56 pull + push
= 60-110 requests har sync par. Internet par yeh minute se ooper chala
jata tha aur browser timeout kar deta tha.

**Fix — bulk sync:** naye `sync-pull-bulk` aur `sync-push-bulk` endpoints.
Ab poora sync **2-3 requests** mein. Payload 400 rows ke chunks mein
bat-ta hai. Client timeout bhi 45s/60s se **180s** kar diya (pehli bhaari
sync ke liye).

  pehle : 60-90 sec (timeout)
  ab    : **0.4 sec** pehli sync (371 push + 484 pull),
          **0.04 sec** jab kuch naya na ho

## 2. "2 rows to upload" hamesha atka rehna
V47 mein maine watermark rok diya tha jab cloud kam rows le. Magar
duplicate-key wali rows **kabhi qubool nahi hongi**, to sync hamesha usi
jagah atka rehta tha — aap ka "2 rows not accepted by cloud" isi ka
nateeja tha.

**Fix — quarantine:** agar sab bachi hui rows *conflict* ki wajah se rukin
to watermark aage barh jata hai (baqi data ruk-ta nahi), aur wo rows
`sync_activity` mein **status REJECTED** ke saath permanently record ho
jati hain. Agar wajah conflict na ho (network/server) to watermark pehle
ki tarah rukta hai aur dobara koshish hoti hai.
Test: conflict ke baad agli 3 syncs — pushed 0, errors 0 (atka nahi) ✓

## 3. Cloud log khali ("no branch computer yet")
Cloud par `sync_activity` table maujood nahi thi (migration nahi chali),
aur logging chupchaap fail ho rahi thi. Ab `syncLogEnsure()` zaroorat parne
par table **khud bana leti hai** — migration chale ya na chale, log kaam
karega.

## Conflict par data ab mehfooz
Test: cloud par `clash@test.pk` (id X), offline par wahi email (id Y) →
push → **1 row rejected**, cloud ka user **bilkul mehfooz**, local ka user
quarantine log mein. Pehle ye khamoshi se overwrite ho jata tha.

## Tested
speed 0.4s / 0.04s ✓ · 5 lagatar syncs par 0 rows (settle) ✓ ·
conflict reject + cloud data safe + quarantine record ✓ ·
conflict ke baad sync atka nahi ✓ · PHP lint clean · 44 pages OK

---

# V49 — VERSION HANDSHAKE: ab confusion mumkin nahi

## Aap ke screenshots ne kya sabit kiya
Log mein `manual · 62867 ms` likha tha. V48 mein sync **0.4 second** leta
hai. 62 second sirf purane per-table tareeqe se lagta hai — yani:

  * aap ka **offline package purana** hai (V48 wala download nahi kiya)
  * aur cloud bhi purana hai (warna log khali na hota)

Yani meri pichli teen updates aap ke system par **chal hi nahi rahi thin**.
Yeh meri kotahi thi ke maine yeh cheez software se check-able nahi banayi.
Ab bana di:

## Version handshake (naya)
- `sync-ping` ab cloud ka **build** aur `features` (bulk_sync,
  conflict_reject, sync_log) bhi wapas karta hai.
- Offline package ke andar **VERSION** file seal ho kar jati hai.
- Dashboard par do naye box: **"This computer"** aur **"Cloud server"** —
  dono ka build saaf likha.
- Match na kare to laal warning:
  *"Version mismatch - sync fixes will not work. This computer: Vxx |
  Cloud server: Vyy. Deploy the latest build to the cloud, then download
  the offline package again."*
- **Check** (diagnose) mein bhi do naye steps: **Build match** aur
  **Bulk sync support** ("Cloud is on an older build - sync will be slow
  and may time out").

## DNS "curl 6" ka hal
Aap ke log mein: *Could not resolve host: saasversion-production.up.railway.app
(curl 6)*. Pehle ek hi koshish hoti thi aur poora sync gir jata tha. Ab
transient errors (DNS, timeout, 502/503/504) par **3 koshishen** hoti hain
(0.7s, 1.4s backoff). Cloud ka build bhi 5 minute cache hota hai taake har
sync par extra request na jaye.

## Tested
local build V49 · cloud build V49 · **Build match: "Both on V49"** ·
**Bulk sync support: OK** · sync **0.46s** (371 push + 484 pull) ok=true

PHP lint clean · 44 pages OK

---

# V50 — "No offline installation has connected yet" ka asli sabab

## Bug
Cloud par activity **sirf tab** likhi jati thi jab rows > 0:

    if($n>0) syncActivityLog(...);

Aap ka node cloud se **connect ho raha tha**, ping kaamyab, token qubool —
magar us waqt bhejne ko kuch **naya nahi** tha (ya sab kuch conflict par
ruk gaya tha). Nateeja: cloud par ek bhi row na likhi gayi, aur dashboard
kehta raha *"No offline installation has connected yet."*

Yani cloud "connection" aur "data transfer" mein farq hi nahi karta tha.

## Fix — node heartbeat
- Har authenticated sync request (ping / push / pull / bulk) par
  **`syncNodeSeen()`** node ko `sync_nodes` mein record karta hai:
  node code (L1/L2), IP, **app build**, last seen.
- Node har request mein apni pehchan bhejta hai:
  `X-Node-Build`, `X-Node-Code` headers. `cloudOnline()` ka ping bhi ab
  token ke saath jata hai taake heartbeat ban sake.
- `has_offline_node` ab **`sync_nodes`** par mabni hai — rows bheje baghair
  bhi node nazar aata hai.
- Cloud dashboard par naya section **"Connected branch computers"**:
  har node ka code, IP, build aur last seen.
- Cloud card par **"Server build"** box bhi (V49 ke handshake ke saath
  mil kar dono taraf ka version ek nazar mein).

## Tested (cloud log bilkul khali kar ke, aap jaisa halat)
Node ne sirf `cloudOnline()` ping kiya — koi row nahi bheji:
  sync_nodes: **L1 | 127.0.0.1 | V50 build | ACTIVE** ✓
Cloud dashboard: header tile **"1 branch"**, pill **"1 branch computer"**,
boxes [Server build V50 · Branch computers 1 · Last activity 10:49:57 ·
Received 0 rows · Transfers 0] ✓
View log: **"CONNECTED BRANCH COMPUTERS — 💻 L1 · 127.0.0.1 · build V50 ·
last seen ..."** ✓ page errors 0

PHP lint clean · 44 pages OK

---

# V51 — "X row(s) not accepted by cloud" — ab wajah bhi milegi, aur sync atkega nahi

## Aap ke log ne do kharabiyan dikhayin

**1. Cloud asli wajah wapas hi nahi bhejta tha.**
`sync-push-bulk` sirf `applied` aur `conflicts` lautata tha. Agar row
duplicate ke ilawa kisi aur wajah se fail hoti (data too long, invalid
value, missing column) to wo error **cloud ke andar hi reh jata tha** aur
node par be-maani message aata: *"1 row(s) not accepted by cloud"*.
**Fix:** cloud ab `row_error` bhi wapas bhejta hai — poora SQL error.
Ab log mein aisa likha aayega:
`payments: 1 row(s) rejected by cloud - SQLSTATE[22001]: String data, right truncated...`

**2. Sync hamesha usi jagah atka rehta tha.**
Aap ke log mein wahi 6 tables (users, orders, order_items, payments,
kitchen_tickets, kitchen_ticket_items) har run mein dobara fail ho rahi
thin — 3:47 se 3:52 tak. Wajah: V48 mein maine sirf *duplicate key* wale
case ko "permanent" mana tha. Baqi har failure par watermark ruk jata tha,
to wahi rows hamesha retry hoti rahin aur peeche ka poora data bhi ruka
raha.
**Fix — retry limit:** har table ka apna counter (`sync_retries`).
3 nakaam koshishon ke baad wo rows **quarantine** ho jati hain
(`sync_activity` mein status REJECTED + poori wajah), watermark aage barh
jata hai, aur baqi data behta rehta hai. Kaamyabi par counter reset.
Duplicate-key wala case pehli hi dafa permanent maana jata hai (dobara
kabhi qubool nahi hoga).

## Tested (asli sealed package)
Duplicate user: run 1 mein error, run 2-5 **saaf** (atka nahi) ✓
Data-too-long payment: run 1-3 mein **asli SQL error** nazar aaya,
run 4-5 saaf, row quarantine mein
`payments | 1 | REJECTED | ... SQLSTATE[22001]: String data, right truncat...` ✓

PHP lint clean · 44 pages OK

---

# V52 — Schema comparison: ab wajah khud pakri jayegi

## Aap ke naye log ka matlab
Message aaya: *"2 row(s) rejected by cloud"* — **bina kisi wajah ke**.
V51 mein cloud ko `row_error` bhejna tha. Wajah nadarad hone ka matlab
sirf ek hai: **cloud par abhi V51 se purana build hai**, jo yeh field
lautata hi nahi.

Ab yeh soorat chup nahi rahegi — message khud batayega:
*"N row(s) rejected by cloud - cloud is on an older build (V44 build ...)
and cannot report the reason - deploy V52 build ... to the server"*

## Naya tool: Schema comparison (Check button mein)
Row rejection ki sab se aam wajah **schema drift** hoti hai — cloud ka
column ghayab, chhota, ya NOT NULL. Naya `sync-schema` endpoint cloud ka
schema deta hai aur node apne se compare karta hai. **Check** mein naya
step **"Schema match"**:

  FAIL Schema match  orders.bill_no: cloud column is smaller
                     (varchar(40) vs varchar(80)) - rows can be rejected
                     | orders.notes: missing on cloud
                     | payments.provider_reference: cloud column is smaller

Teen tarah ke masle pakre jate hain:
  * column cloud par **ghayab** (us column ka data gir jata hai)
  * cloud ka column **chhota** (rows reject hoti hain)
  * cloud par **NOT NULL** column jo node ke paas hai hi nahi

## Tested
Barabar schema par: sirf asli drift dikhi (orders.bill_no 40 vs 80) ✓
Jaan boojh kar cloud par column chhota + ek column drop kiya:
teeno issues theek pakre gaye ✓
Purane cloud build ka message: verify kiya ✓
Diagnose ke ab **11 steps** (Build match + Schema match samet) ✓

PHP lint clean · 44 pages OK

---

# V53 — Sync test suite (28 tests) + do naye bugs pakre gaye

Aap ke kehne par is dafa **baar baar zip nahi banayi**. Ek proper test
suite likhi (`tools/sync_suite.py`) jo har scenario khud chala kar check
karti hai, aur zip sirf tab banayi jab **28/28 pass** ho gaye.

## Suite ne do ASLI bugs pakre

**1. NULL `site_id` wali rows kabhi pull hi nahi hoti thin.**
Pull ka filter `AND site_id = ?` tha. Jo rows kisi ek branch se bandhi
nahi hotin (misal cloud par banaye gaye customers, jinka site_id NULL
hota hai) wo **kabhi neeche nahi aati thin**. Ab:
`AND (site_id = ? OR site_id IS NULL)`.
Yahi wajah thi ke cloud par banaya customer local par nazar nahi aata tha.

**2. Cloud par table maujood na ho to KHAMOSHI se 0 rows.**
`applyRows()` shuru mein `if (!$rows || !tableExists) return 0;` karta
tha — na error, na wajah. Node ko sirf "rejected by cloud" dikhta tha.
Ab saaf message: *"table does not exist on the cloud database (run the
migrations there)"*.

## Suite kya kya check karti hai (28 tests)
1. Healthy sync · zero par settle · koi table error nahi
2. Speed (5 second se kam) — **0.09s**
3. Duplicate key: wajah, cloud ka data mehfooz, atka nahi
4. Cloud par table ghayab: saaf report
5. Data too long: **asli SQL error**, retry cap, quarantine record
6. Schema comparison: chhota column pakra jata hai
7. Diagnose ke 11 steps (Build match + Schema match samet)
8. Node cloud par register + apna build batata hai
9. Two-way: cloud -> local aur local -> cloud dono
10. Bill prefix (L2-) mojood
11. Sync log: runs, per-table detail, cloud activity

## Nateeja
    28/28 passed
Suite `tools/sync_suite.py` mein shamil hai — aainda kisi bhi tabdeeli ke
baad chala kar poora sync ek command mein verify kiya ja sakta hai.

PHP lint clean · 44 pages OK · dashboard + POS JS clean · local node OK

---

# V54 — Aap ke `users` masle ka ASLI sabab

## Message
`users: 1 row(s) rejected by cloud - table does not exist on the cloud
database` — halanke `users` table cloud par yaqeenan mojood hai.

## Sabab
`Sync::columns()` schema ka naam **config se** leta tha:

    $db = $GLOBALS['config']['db']['database'];
    SELECT ... FROM information_schema.columns WHERE table_schema = $db

Railway (aur aam managed hosting) par config ka database naam us schema se
**mukhtalif** ho sakta hai jis se connection asal mein bana hota hai —
env variable alag ho, ya alias ho. Us soorat mein `information_schema`
**khali** lautata hai, `tableExists()` false ho jata hai, aur **har** table
"cloud par mojood nahi" keh kar reject ho jati hai.

Aap ke log mein sirf `users` isliye nazar aa rahi thi ke us waqt sirf usi
ke paas bhejne ko naya data tha. Baqi tables ka bhi yahi anjaam hota.

Ghaur talab: V53 se pehle yeh masla **khamoshi** se hota tha (sirf
"rejected by cloud", koi wajah nahi). V53 ne wajah dikha di, aur usi se
asli bug pakra gaya.

## Fix
`columns()` ab teen tarah se schema dhoondta hai:
1. `WHERE table_schema = DATABASE()` — asli connection ka schema
2. config wala naam (fallback)
3. `SHOW COLUMNS FROM table` (aakhri koshish)

## Tested
Cloud config mein jaan boojh kar `'database' => 'wrong_db_name_xyz'` kiya:
push phir bhi kaamyab — `{"ok":true,"applied":1}` aur row cloud DB mein
mojood ✓ (pehle "table does not exist" aata)
Poori suite fresh node par: **28/28 passed** ✓
PHP lint clean · 44 pages OK · local node OK

---

# V55 — Purane bills khud renumber ho kar sync ho jate hain

## Aap ka log
`⬆ 1  ⬇ 187 rows` — sync **chal para**. users push, aur 187 rows neeche
aayin (menu, inventory, orders 13, order_items 36, payments 7,
kitchen_tickets 11, ui_records 33 waghera). Sirf ek cheez bachi:

`orders: 3 row(s) rejected - duplicate key (another device already used
that number)`

Yeh wo **purane bills** hain jo node-prefix se pehle bane the (0001, 0002...)
aur cloud ke apne usi din ke bills se takra rahe the. Nayi bills (L2-...)
pehle se mehfooz hain.

## Fix — automatic renumbering
Ab jab cloud koi bill duplicate number ki wajah se reject kare, node **usi
row ko apna prefix de deta hai** (`0007` -> `L2-0007`) aur agli sync mein
wo chali jati hai. Sirf wahi rows chhui jati hain jo cloud ne reject ki
hain — cloud ka apna bill bilkul nahi badalta. Local par bhi takrao ho to
chhota suffix laga diya jata hai.

Log mein saaf likha aata hai:
*"1 old bill(s) renumbered with this computer's prefix - they will upload
on the next sync"*

## Test suite ab 34 tests
Naya scenario shamil: offline par bina prefix ka bill + cloud par usi
number ka bill ->
  conflict pakra gaya ✓ local bill **L2-7442** ban gaya ✓
  agli sync mein cloud tak pohancha ✓ cloud ka original bill (1000) bilkul
  na chhua gaya ✓

## Nateeja
    34/34 passed
PHP lint clean · 44 pages OK · dashboard + POS JS clean · local node OK

---

# V56 — Platform Console (Super Admin) ka mukammal naya portal

## Naya shell — restaurant jaisa
Purana portal ek dark single-page tha (478 lines, 3 tabs) jo baqi software
se bilkul mail nahi khata tha. Ab wahi design system (`shared.css`) —
left sidebar + header + cards + tables, light theme, wahi tokens.

**Sidebar (9 screens, 4 groups):**
  Overview   : Dashboard · Sync Monitor
  Businesses : All Businesses · Create Business
  Data       : Backup & Reset · Import Data
  System     : Audit Log · Health · Account

Purane portal ke saare kaam mehfooz: business list, detail, renew,
reset password, branding, features, WhatsApp, suspend/activate, create,
plans, health, password change.

## Naya: Backup → Download → Factory Reset
- **Download backup**: poora business ek JSON file mein.
  *Full* (master + transactions) ya *Master only*.
  Har backup ka record rakha jata hai.
- **Factory reset** do hifazaton ke saath:
  1. business ka **poora naam type** karna zaroori
  2. **pichle 1 ghante mein backup** liya gaya ho, warna server mana kar
     deta hai
  Do mode: *Transactions only* (menu/items/customers mehfooz) ya
  *Everything*.

## Naya: Import — wahi backup file wapas
Drag/click se file chunein → **preview** (kaun si tables aayengi, kitni
rows) → import. Sirf **master data**: menu, items, recipes, inventory,
suppliers, customers, staff/roles, tables, printers.
Transactional data (orders, payments, shifts) jaan-boojh kar **import nahi
hota** — bill numbers aur stock effects kharab ho jate.
Duplicate par "Skip" ya "Overwrite" ka option. Import history bhi.

## Naya: Sync Monitor + Audit Log
- **Sync Monitor**: saare businesses ke branch computers — node code, IP,
  build, last seen; 24h transfers/rows; aur rejected/failed transfers.
- **Audit Log**: har super-admin action (backup, reset, import) — kis ne,
  kab, kis business par, kitni rows.

## Tested (asli browser, poora cycle)
Login → console ✓ · 9 sidebar links ✓ · dashboard 8 KPIs ✓
Businesses: 3 rows, 8 actions ✓
Sync Monitor: 1 node (L2, V55 build), 677 transfers, 16,350 rows ✓
Backup: **backup_royalgrill_FULL_20260826_1701.json — 25 tables, 162 rows,
157KB** ✓
Reset guard: galat naam par block, orders **mehfooz** ✓
TXN reset: 95 rows deleted, **menu_items 9 salamat** ✓
FULL reset: sab 0 → **import** se master data wapis (menu 9, customers 2),
orders 0 hi rahe ✓
Audit log: IMPORT / FACTORY_RESET / BACKUP sab record ✓
Sync suite regression: **34/34 passed** · PHP lint clean · 44 pages OK

---

# V57 — Factory Reset ab waqai "factory" reset, aur console ka design theek

## 1. Factory reset adhoora tha (asli bug)
`AdminData` mein tables ki **hardcoded list** thi — 47 tables. Database
mein tenant/site se bandhi **86 tables** hain, yani **48 tables reset se
chhoot rahi thin**: users, user_roles, user_module_access, devices,
paired_devices, loyalty_accounts, refunds, stock_batches, stock_transfers,
journal_entries, order_status_history, printer_jobs, modifier_groups,
site_settings, tax_profiles waghera. Isi liye "reset" ke baad bhi
restaurant ka data mojood rehta tha.

**Fix:** ab list nahi — `wipeableTables()` **information_schema se khud**
har wo table nikalta hai jis mein `tenant_id` ya `site_id` ho (73 tables),
aur sirf platform-level tables chhoRta hai (tenants, sites, plans,
subscriptions, sync logs, admin logs).

**FULL mode ka matlab ab waqai yeh hai:** sab kuch mit jata hai, **sirf
admin login bacha rehta hai** — uska user record, uska role aur us role ke
modules. Uske baad branch defaults (payment methods, floors, tables, units,
locations) dobara seed ho jate hain taake owner foran kaam shuru kar sake:
apne users banaye, items banaye, aur zero se chale.

**TXN mode** pehle ki tarah: sirf transactions, master data aur staff
mehfooz.

## 2. Console ka design
Pichli dafa maine `.card` / `.inp` / `.tablewrap` jaisi classes use ki
thin jo `shared.css` mein **hain hi nahi** — is liye panels bay-border,
tables nange aur inputs be-style nazar aa rahe the. Ab poora markup asli
design system par: `.panel` / `.panel-head` / `.panel-body` /
`.table-wrap` / `.table` / `.field` / `.form-grid` / `.t-main` / `.t-sub`.

Saath:
- KPI grid ab **4 × 2** (pehle 5+3 mein bad-shakal toot raha tha)
- Renewals/payments panels **1.35fr : 1fr** aur tareekh/raqam `nowrap` —
  pehle "2026-09-25" do lines mein toot raha tha
- Header ka build pill ab asli build dikhata hai (**V57**). `sync-ping`
  bina token ke 401 de raha tha, is liye pill hamesha "—" tha.

## 3. Ek aur bug
Import ka `error_text` column VARCHAR(500) tha aur lambi error list us se
barh jati thi — poora import **fail** ho jata tha
(*"Data too long for column 'error_text'"*). Ab column TEXT hai aur value
bhi truncate hoti hai.

## Tested
Factory reset verifier (`tools/reset_verify.py`) — har tenant-scoped table
ginta hai: **"FACTORY RESET CLEAN — only admin login + defaults remain"** ✓
Browser (Lahore Karahi House, FULL): menu 1→0, orders 1→0, inventory 1→0,
**users 1→1 (admin salamat)** ✓
Backup → download → FULL reset → import se master data wapis ✓
Sync suite regression: **34/34 passed** · PHP lint clean · 44 pages OK

---

# V58 — Complete business delete + Command Console

## 1. Business delete ab waqai poora delete
Pehle koi mukammal delete tha hi nahi. Ab `AdminData::deleteBusiness()`:
- **73 tenant-scoped tables** (information_schema se khud nikali gayi, hardcoded list nahi)
- platform-level rows bhi: sync_activity, sync_nodes, sync_runs, sync_cursors,
  admin_backups/imports/audit, subscription_payments, tenant_subscriptions,
  site_modules, site_settings, **sites**, **organizations**, signup_requests
- aakhir mein khud **tenants** row

Yani business ka naam-o-nishan tak nahi bachta. Factory reset se aage — wahan
admin login bachta hai, yahan wo bhi nahi.

**Hifazat:** business ka **poora naam type** karna lazmi (`confirm_name`), aur har
delete audit log mein jata hai.

**Tested (asli API se business bana kar):** 13 tables mein 38 rows → delete →
39 rows from 14 tables → **AFTER: 0 tables, tenants 0, sites 0** ✓

## 2. Command Console — naya screen
Super admin mein **Command Console** (sidebar → System). Asli terminal:
dark screen, `>` prompt, Up/Down arrow se purani commands, quick-command chips.

**Commands:**

    BUSINESSES
      list                              sab businesses
      info <slug>                       tafseel + counts
      users <slug>                      staff accounts
      footprint <slug>                  kis table mein kitna data hai
      suspend <slug> | activate <slug>
    DATA
      backup <slug> [full|master]       file download shuru
      reset <slug> [txn|full] --confirm "<name>"
      delete <slug> --confirm "<name>"
    MONITORING
      nodes                             branch computers
      sync [slug]                       transfer activity
      audit [slug]                      super-admin actions
    DATABASE
      tables                            tenant-scoped tables
      query SELECT ...                  read-only, max 100 rows
    CONSOLE
      clear · version · help

**Hifazatein (sab server par, browser mein nahi):**
- `reset` / `delete` bina `--confirm "<exact name>"` chalti hi nahi
- galat naam par: *"Confirmation does not match. Expected: …"*
- `reset` se pehle **1 ghante ke andar backup** lazmi
- `query` sirf SELECT/SHOW/DESCRIBE — INSERT/UPDATE/DELETE/DROP block
- bina LIMIT wali query par khud `LIMIT 100`
- har amal audit log mein

**Tested (browser):**
  `delete grill-two` → "needs confirmation" ✓
  `delete grill-two --confirm "Wrong Name"` → "does not match. Expected: Grill Two" ✓
  `reset grill-two full --confirm "Grill Two"` → "Take a backup first" ✓
  `query DELETE FROM tenants` → "Only SELECT / SHOW / DESCRIBE are allowed" ✓
  `delete grill-two --confirm "Grill Two"` → poora saaf, tenants row 0 ✓

## Chhoti baat
`mb_strlen` hata di — Railway image par mbstring nahi hai, console har command
par crash kar jata.

## Regression
Sync suite **34/34** · reset verifier CLEAN · PHP lint clean · 44 pages OK

---

# V59 — Selective purge (transactions / logs) + behtar console messages

## Naya command: purge
`reset` sab kuch mita deta hai. Ab **chuni hui cheez** mitai ja sakti hai:

    purge <slug> <what> --confirm "<name>"
    purge <slug> orders --before 2026-01-01 --confirm "<name>"

**Groups:**
  transactions  orders + shifts + stock + qr + expenses (sab kuch)
  orders        orders, order_items, payments, voids, kitchen tickets,
                order_status_history, refunds, delivery, fiscal invoices
  shifts        cashier_shifts, cash movements, handovers
  stock         transactions, lines, balances, adjustments, batches,
                counts, transfers, GRN, purchase orders
  qr            qr_orders, qr_sessions
  expenses      expenses, supplier_payments
  logs          audit_logs, notification_queue, printer_jobs, background_jobs
  sync          sync_activity, sync_runs, conflicts, inbox, outbox, cursors
  all-logs      logs + sync

`--before YYYY-MM-DD` se sirf us tareekh se **purana** data jata hai (jis
table mein `business_date` / `created_at` / `opened_at` / `paid_at` ho).

**Hifazat:** business data ke liye pehle backup lazmi (1 ghanta), naam se
confirm lazmi. **Logs ke liye backup ki shart nahi** — wo mehez record hain.
Transactions purge ke baad sync watermarks reset ho jate hain.

## Console messages theek kiye
Aap ne `reset <a> txn` chalaya aur sirf itna aaya:
*"needs confirmation. Add: --confirm "<exact business name>""* — jis se
pata hi nahi chalta ke asal mein kya type karna hai.

Ab console **poori command bana kar deta hai**:

    > reset <royal-grill> txn
    Note: < > sirf placeholders hain — bina brackets likhein.
    This command deletes data, so it needs the business name to confirm.
    Run:  reset royal-grill txn --confirm "Royal Grill"

- `<slug>` jaise angle brackets khud saaf ho jate hain (aur bata diya jata hai)
- business ka asli naam DB se nikal kar command mein bhar diya jata hai
- galat slug par: *"No business with slug 'x'. Type 'list' to see the exact slugs."*

## Tested
Galat group → saaf list. Bina confirm → poori command. Logs purge bina backup
ke chali (953 sync rows) ✓ Transactions purge bina backup ke ruki ✓
`--before` ne sirf purani rows hatayin, aaj wali mehfooz ✓
Browser: koi JS error nahi ✓ PHP lint clean · 44 pages OK

---

# V60 — "Request failed" ne wajah chhupa di thi

## Masla
Aap ne `reset a txn --confirm "a"` chalaya aur sirf **"Request failed"**
aaya. Yeh message client ka default tha jab server ka jawab **JSON nahi**
hota (PHP fatal, 500, ya timeout). Asli wajah kahin nazar hi nahi aati thi.

Mere sandbox par bilkul yehi command kaamyab chalti hai
(`Reset complete — 1 rows deleted`), aur `factoryReset` TXN 0.00s, FULL
0.03s leta hai. Yani masla aap ke server par hai — aur us tak pohanchne ka
koi zariya nahi tha.

## Fix 1 — console ka jawab hamesha JSON
`sa-console` ab:
- `set_time_limit(180)`
- `register_shutdown_function` — koi PHP fatal aaye to bhi **JSON** jawab
  jata hai, message aur file:line ke saath
- har Throwable catch ho kar console mein saaf likha jata hai

## Fix 2 — client asli jawab dikhata hai
Console ka apna fetch: agar jawab JSON na ho to
`Server returned HTTP 500 (not JSON)` + response ka saaf-kiya hua matn,
ya khali ho to *"Empty response — the command may have timed out."*
Network fail ho to: *"Could not reach the server: …"*

## Fix 3 — naya command: selftest
Ab koi command fail ho to **`selftest`** ya **`selftest <slug>`** chalayein.
Yeh sab kuch check karta hai:

    OK   PHP version                8.3.6
    OK   Time limit                 0s
    OK   Database                   aio_cloud
    OK   Table admin_backups        10 columns
    OK   Table sync_state           7 columns      (…9 tables)
    OK   FK toggle permission       allowed
    OK   Wipeable tables            73 found
    OK   Business royal-grill       Royal Grill
    OK   Recent backup (1h)         7 found — reset allowed
    OK   Data footprint             9 tables, 34 rows

Koi table MISSING ho to wahin likha aata hai *"run the migrations"* — yani
Railway par migration na chali ho to foran pata chal jata hai.

PHP lint clean · 44 pages OK · console JS clean

---

# V61 — MySQL 8 vs MariaDB: information_schema ke column names

## Aap ke selftest ne asli bug pakar diya

    Server returned HTTP 200 (not JSON)
    Warning: Undefined array key "table_name" in AdminData.php on line 169

**Sabab:** MySQL 8 `information_schema` ke column names **UPPERCASE**
(`TABLE_NAME`, `COLUMN_NAME`) lautata hai; MariaDB lowercase. Mera saara
code lowercase maan raha tha. Aap ka Railway MySQL 8 par hai, mera sandbox
MariaDB par — is liye mere yahan sab theek chalta raha aur aap ke yahan
`wipeableTables()` **khali/kharab** aata tha.

Isi ek farq se:
- `reset` / `delete` / `purge` — "Request failed"
- warnings seedhe response mein chhap kar JSON tor dete the

## Fix 1 — har query ab explicit lowercase alias
`SELECT table_name AS t`, `SELECT column_name AS c` waghera. Ab jawab
dono databases par ek jaisa aata hai. Files: `AdminData.php`, `Sync.php`,
`api.php`, `migrate_sync_columns.php`, `migrate_collation.php`,
`bootstrap_offline.php`.
Audit chalaya — **sab queries case-safe** ✓

## Fix 2 — warnings ab response mein nahi jatin
`api.php` ke shuru mein: `display_errors=0`, `log_errors=1`.
Koi PHP warning aaye to log mein jayegi, JSON kharab nahi karegi.
Yehi wajah thi ke ek chhoti si warning poore console ko tor deti thi.

## Tested
`selftest royal-grill` — poora OK ✓
`footprint royal-grill` — 9 tables ✓
`reset royal-grill txn --confirm "Royal Grill"` — saaf JSON ✓
`purge royal-grill logs --confirm "Royal Grill"` — saaf JSON ✓
Sync suite **34/34** · PHP lint clean · 44 pages OK

---

# V62 — Delete Everywhere + Sync ka DELETE CHANNEL

## Asli shikayat

> "Restaurant mein user ka password change karne aur user delete karne ka
>  option nahi aa raha. Is ke elawa koi item delete karna chahun wo bhi
>  nahi ho rahi. Koi bhi entry delete karna chahun usko delete hona chahiye."
>
> "Local par still old data kyun aa raha hai jab ke live par data 0 hai,
>  transaction delete kiye hain."

Yeh **do alag masle nahi the** — ek hi jar thi.

## Jar 1 — Sync mein "kya mit gaya" ka koi channel tha hi nahi

Sync engine sirf do kaam janti thi:

    changedRows()  ->  SELECT * FROM table WHERE updated_at > ?    (kya badla)
    applyRows()    ->  wahi id mile to UPDATE, warna INSERT        (upsert)

`Sync.php` mein `tombstone` / `deleted` ka ek lafz bhi nahi tha.

Jab cloud par `factoryReset()` / `purge()` **hard DELETE** chalate the, row
bina koi nishan chhore ghayab ho jati thi. Node agli pull par poochta:
"mere watermark ke baad kya naya hai?" — cloud kehta "kuch nahi". Node ko
khabar hi nahi hoti thi ke hazaron rows mit chuki hain.

Aur is se bara khatra: node ka `sync_state` kabhi reset ho jaye (offline
package reinstall, DB restore, `migrate_sync_columns.php` dobara) to wo
**saara purana data ek jhatke mein wapas cloud par push** kar deta.
Live 0 par thi magar **stable nahi** thi.

### Fix — `sync_tombstones`

`scripts/migrate_delete_support.php`:
- `sync_tombstones` — har hard delete ka nishan (tenant/site scoped)
- `sync_tombstones_applied` — LOCAL rehta hai, kabhi sync nahi hota
  (warna ek node ka "apply ho gaya" doosre par chala jata aur wahan
  delete kabhi hoti hi nahi)
- `deletion_log` — har delete/void/restore ka audit
- 24 tables par `deleted_at` (recipes, dining_tables, floors, printers,
  goods_receipts, cashier_shifts, expenses, roles, promotions ...)

`Sync.php`:
- `applyTombstones()` — pull ke BAAD chalta hai. Do modes:
  `ROW` (ek row) aur `WIPE` (us table ki poori tenant/site scope).
- `before_ts` guard — WIPE sirf us waqt tak ka data uthata hai, is liye
  reset ke **baad** ka naya data kabhi nahi mit sakta, chahe wohi
  tombstone dobara process ho jaye.
- `TOMBSTONE_DENY` — platform tables (tenants, platform_users,
  subscriptions, sync_* khud) kabhi tombstone se delete nahi hotin.
- Wipe ke baad us table ka watermark reset — warna node ka aage barha
  hua watermark us table ko dobara kabhi laata hi nahi.
- `cfg()` ab `sync_tombstones` ko push/pull lists mein **zabardasti**
  daalta hai — purani config wale nodes ko bhi mil jata hai.
- Errors run-log mein jaate hain. Khamosh nahi.

`AdminData.php`:
- `factoryReset` / `purge` / `deleteBusiness` ab delete se **pehle**
  wipe marker likhte hain. Marker na bane to **reset ruk jata hai** —
  warna wahi "cloud khali, node bhara" wala haal.
- Tombstone tables `NEVER_WIPE` mein daal di gayin.

### Naya console command — `resync`

    resync <slug> [transactions|all] --confirm "<name>"

**Cloud ka data bilkul nahi chhoota.** Sirf wipe markers likhta hai;
agli sync par node apni wahi tables khud saaf kar ke cloud se dobara
bharta hai. Yeh us haalat ka hal hai jahan reset purane build par hua
tha aur tombstone bana hi nahi.

    tombstones [slug]     — pending / applied delete signals

### Node-side tools
`scripts/node_reset.php` + `RESET_NODE.bat` (`--dry-run` ke saath) —
pure-offline installation ya purane build ke cases ke liye. Pehle node
par kuch saaf karne ka koi tareeqa hi nahi tha.

## Jar 2 — Delete KHAMOSHI SE fail hoti thi

`module.js` mein:

    function dbDelete(id){ window.DBApi.req('records-delete',{...}) }   // result discard
    remove:function(id){ if(this.mode==='db'){dbDelete(id);return} ... } // no check

Server jo bhi kahe — `419 token expired`, "Wastage cannot be deleted",
permission denied, FK error — UI phir bhi **"Record removed"** dikhata
tha aur `reload()` par row wapas aa jati thi. `records-delete` khud bhi
`rowCount()` check kiye baghair `ok()` kar deta tha.

### Fix
- `records-delete` ab `rowCount()` check karta hai; 0 rows par `fail()`.
- `dbDelete()` hata diya. `module.js` ab `DeleteKit` se guzarta hai.
  **Ek fix = 23 module pages theek.**
- `public/delete_kit.js` — poore software ka ek hi confirm modal.
  Sirf `shared.css` ki mojooda classes. `BLOCKED` par asli wajah,
  + "Sirf Inactive karein" + "Force delete (manager password)".
  `router.php` har page par inject karta hai.

## Jar 3 — Bohat si jagah delete ka rasta tha hi nahi

`src/Services/DeleteService.php` — 17 entities ka registry, teen natije:

    DELETED  — soft (deleted_at + is_active=0), sync-safe
    BLOCKED  — asli wajah: "42 bill lines mein use hua hai"
               + can_deactivate / can_force
    FORCED   — Admin, manager password, child rows + tombstone

- Permission: Owner/Admin, warna `user_form_permissions.can_delete`
  (yeh table maujood thi magar kabhi use hi nahi ho rahi thi).
- GRN / wastage delete par **stock reverse** hota hai — warna inventory
  hamesha ke liye galat reh jati.
- Recycle bin — 30 din tak soft-deleted rows wapas.
- **Bill delete nahi hota** — `voidOrder()`: order VOID, payments
  cancel, becha hua stock wapas, bill number history mein salamat.
  FBR aur accounting dono mehfooz.

### UI jahan pehle kuch bhi nahi tha

| Page | Ab |
|---|---|
| `users_access.html` | Access / **Password** / Suspend / **Delete** + status tag |
| `inventory_creation.html` | item delete (table + card), category chip par × |
| `recipe_making.html` | recipe delete |
| `purchasing.html` | GRN cancel (stock reverse), GRN no ab UUID ki jagah |
| `restaurant_pos.html` | item card par delete (manager-only) |

**Users page ka password:** option "maujood" tha magar chhupa hua —
`accessState()` har user ka `password:''` bhejta tha, edit modal ka
field khali aata tha aur label "Temporary Password" likha tha. Kisi ko
andaza hi nahi hota tha ke wahan likhne se password badal jayega. Ab
alag `user-password` endpoint aur apna modal.

### Naye endpoints
`entity-delete` · `entity-restore` · `recycle-bin` · `delete-entities`
`order-void` · `user-password` · `user-status` · `user-delete`

`user-delete` / `user-status` par guards: khud ko delete/suspend nahi,
aakhri active admin nahi.

## Bonus — `tables_floors` ka split brain

`tables` module `ui_records` mein likhta tha jabke POS `dining_tables`
se padhta hai. **Do alag jagah** — is liye wahan banayi hui table POS
par kabhi nazar nahi aati thi aur wahan delete karne ka POS par koi
asar hi nahi hota tha. Ab `ModuleBridge` se dono ek hi table par.

## Bonus 2 — `offline_sync.html` ki JS MARI HUI THI

    } else { toast(...); }
    else{ toast(...); }        <-- do else

JS syntax error. Us page ka **poora script kabhi chala hi nahi** —
"Sync now" button dead tha aur status kabhi refresh nahi hota tha.
Theek kar diya (aur ab `removed N rows` bhi dikhata hai).

## Testing

    node --check   44 pages ka inline JS + public/*.js   -> 0 fail
    PHP structural audit (braces/strings/heredoc)         -> 0 fail
                          (views/modules/dashboard.php ek
                           false positive hai: "Today's" ka
                           apostrophe PHP tags ke bahar)

**NahI chalaya** (sandbox mein PHP binary maujood nahi tha):
`php -l`, `tools/check_pages.php`, `tools/sync_suite.py`,
`tools/reset_verify.py`. Yeh aap ke sandbox par chalana zaroori hai.

## Deploy ke baad pehla kaam

1. Cloud deploy — migration `docker-entrypoint.sh` mein khud chalti hai.
2. Platform Console -> Command Console:

       resync royal-grill transactions --confirm "Royal Grill"

   (Cloud ka data safe rehta hai — sirf markers bante hain.)
3. Branch computer par app kholein, dashboard par **Sync now**.
4. Tasdeeq: `tombstones royal-grill` — markers `applied` dikhne chahiyen.

---

# V62.1 — 24 MINUTE WALA KHAMOSH LOGOUT (aur jhoot bolta hua error)

## Alamat

    > list
    Server returned HTTP 500 (not JSON)
    Invalid CSRF token.

Saath hi restaurant user ka login "lagta hi nahi tha".

## Asli wajah — yeh V62 ka bug nahi tha, shuru se maujood tha

`session-diagnostics.php` ne pakra:

    cookie_matches_id   : false
    csrf_token_present  : false
    session_dir_writable: true      <- storage bilkul theek thi
    session_files_on_disk: 19

Yani session likhi ja rahi thi, magar **mit ja rahi thi**.

Docker image (`php:8.2-apache`) mein koi `php.ini` hai hi nahi, is liye
PHP ke built-in defaults chal rahe the:

    session.gc_maxlifetime = 1440    (SIRF 24 MINUTE)
    session.gc_probability = 1
    session.gc_divisor     = 100     (~har 100 requests par GC)
    session.lazy_write     = 1

Do alag tareeqon se yeh maar deta tha:

1. **24 minute ki khamoshi = logout.** Restaurant mein yeh rozana hota
   hai — cashier zara der bill na kaate, wapas aa kar sab kuch fail.

2. **`lazy_write=1` ki wajah se CHALTA HUA session bhi marta tha.**
   Agar session ka DATA na badle to PHP file dobara likhta hi nahi, is
   liye uska mtime purana rehta hai — aur GC use bhi uda deta hai,
   chahe user musalsal kaam kar raha ho.

### Loop jo tootta hi nahi tha

Session GC ho jane ke baad har POST naya khali session banata tha
jismein `_csrf` hota hi nahi (kyunke `csrf_json()` `Csrf::token()` tak
pohanchne se PEHLE fail kar deta tha). Page ka JS purana token pakde
baitha rehta tha. Nateeja: **page refresh kiye baghair kabhi theek nahi
hota tha.**

## Fix 1 — session ab ek poori shift chalti hai

`src/bootstrap.php`:
- `gc_maxlifetime` = 12 ghante
- `lazy_write = 0` — har request par mtime taza, active user kabhi nahi nikalta
- `gc_divisor` 100 -> 1000 (kam faltu GC)
- `cookie_lifetime` 12 ghante + har request par cookie ki expiry aage
- `cookie_secure` HTTPS par khud on (`X-Forwarded-Proto` bhi dekhta hai),
  local HTTP node par off — warna offline installation ka login toot jata

## Fix 2 — error khud jhoot bol raha tha

`Csrf::verifyOrFail()` yeh karta tha:

    http_response_code(419); exit('Invalid CSRF token.');

- **419 non-standard status hai.** Apache use reason-phrase ke baghair
  aage nahi bhej pata — browser tak **500** pohanchta tha. Isi liye
  "HTTP 500 (not JSON)" dikhta tha, jo asli wajah chhupa raha tha.
- **Plain text tha, JSON nahi**, aur `exit` exception nahi phenkta —
  is liye `api.php` ka `try/catch` kabhi chala hi nahi.

Ab exception phenkta hai, `api.php` saaf JSON deta hai **403** ke saath
(jo Apache theek se aage bhejta hai) aur message asli baat batata hai.

## Fix 3 — CSRF auto-recovery

Naya `csrf-token` endpoint (GET, is liye khud CSRF se guzarta nahi).
`db_api.js` aur Platform Console dono: token expire mile to naya le kar
**khud ek dafa retry** karte hain. Deploy/restart ke baad purana tab
khula reh jane par ab kuch nahi tootega.

## Fix 4 — boot par storage ki guarantee

`docker-entrypoint.sh` ab Apache start hone se pehle `storage/sessions`
banata hai, `www-data` ko deta hai, aur **www-data ban kar likh kar
test karta hai**:

    [boot] session storage writable OK
    [boot] WARNING: storage/sessions NOT writable by www-data - login/CSRF will fail

## Fix 5 — diagnostics ab yeh sab dikhata hai

`/session-diagnostics.php` mein naye fields: `gc_maxlifetime_sec`,
`gc_maxlifetime_human`, `lazy_write`, `cookie_secure`,
`cookie_lifetime_sec`. Naya verdict `SHORT_LIFETIME` — agar
`gc_maxlifetime` ek ghante se kam ho to saaf keh deta hai ke purana
build chal raha hai.

---

# V62.2 — "0 Modules": permissions kabhi sync hui hi nahi

## Alamat

    Local  : Counter 1 · Cashier · Main Branch · 6 Modules · Active
    Online : Counter 1 · Cashier · Main Branch · 0 Modules · Active

Wahi user, wahi role, wahi branch — magar cloud par ek bhi module nahi.
Koi error, koi warning. Bilkul khamosh.

## Do alag bugs, ek saath

### Bug 1 — `user_module_access` sync ho hi nahi sakti thi

Users page jab modules assign karta hai to rows `user_module_access`
mein jati hain. Aur wo table:

| jagah | maujood? |
|---|---|
| `syncTableAllowed()` (server allow-list) | NahI |
| `push_tables` / `pull_tables` (config) | NahI |
| `migrate_sync_columns.php` (`updated_at`) | NahI |

**Teenon jagah gayab.** Yani wo rows kabhi node se bahar nikal hi nahi
sakti thin.

Isi tarah `users`, `user_roles`, `roles`, `role_modules` server ki
allow-list mein to the, magar `config/local.php` ke `push_tables` mein
**ek bhi nahi** — poora staff/permissions ka hissa sync se bahar tha.

### Bug 2 — sync ho bhi jati to bhi 0 hi rehta

`seed_platform_modules.php`:

    ->execute([uuid(), $key, $name, $sort]);

**`uuid()` — har installation par RANDOM.** Cloud par `pos` module ka
id kuch aur, branch computer par kuch aur.

`Auth::moduleKeys()` join `uma.module_id = pm.id` par chalti hai. Node
ki row cloud par pohanch bhi jati to uska `module_id` wahan kisi module
se match hi nahi karta. Join khali -> **"0 Modules"**, khamoshi se.
Yehi `role_modules` par bhi lagu tha.

## Fixes

**1. Module ids ab deterministic**
Naya helper `module_uuid($key)` (`src/helpers.php`) — id `module_key`
se derive hoti hai (UUIDv5 jaisa), is liye har installation par bilkul
wahi nikalti hai. `seed_platform_modules.php` ab yehi use karta hai.

**2. `scripts/migrate_module_ids.php`** — purane random ids ko canonical
par le aati hai. Child references (`user_module_access.module_id`,
`role_modules.module_id`, `site_modules`, `tenant_modules`) **pehle**
update hote hain, phir parent ka id — warna orphan rows reh jayen aur
permissions phir bhi khali dikhein. Aadha-migrate hui soorat mein purani
row merge ho jati hai. Transaction + FK checks off. Idempotent.
Aakhir mein `MODULE_FINGERPRINT` print karta hai.

**3. Permission tables sync mein**
- `syncTableAllowed()`: `user_module_access`, `user_site_access`,
  `platform_modules`
- `migrate_sync_columns.php`: `user_module_access`, `user_site_access`
  (`updated_at` ke baghair sync inhen khamoshi se skip karti thi — wahi
  V40 wala masla)
- `config/local.php` ke push **aur** pull dono mein: `users`,
  `user_roles`, `roles`, `role_modules`, `user_module_access`,
  `user_form_permissions`
- `Sync::cfg()` inhen **zabardasti** daalta hai (jaise `sync_tombstones`
  ke saath), taake purani config wale nodes ko bhi mil jaye

`users` two-way chalta hai, engine ke mojooda last-write-wins ke saath:
jo `updated_at` naya, wo jeetta hai. Password change `updated_at` bump
karta hai, is liye taza password hamesha jeetta hai.

**4. Yeh khamoshi dobara na ho — handshake mein module fingerprint**
`sync-schema` ab `module_fingerprint` bhi lautata hai. `Check` /
`Sync::diagnose()` mein naya step **"Module IDs match"**:

    MISMATCH - permissions sync NahI hongi (har user online "0 Modules"
    dikhega). Dono taraf `php scripts/migrate_module_ids.php` chalayein.

**5. UI par nazar aaye**
Users page par `0 Modules` ab laal `.tag red` hai. Pehle wo bilkul
normal lagta tha — isi liye mahinon chhup sakta tha.

**6. Naya console command**

    permissions <slug>     (ya: perms <slug>)

Har user ke modules aur unka zariya (DIRECT vs VIA ROLE), aur upar
module fingerprint. Jin users ke paas ek bhi module nahi wo laal, aur
neeche saaf hidayat.

## Deploy ke baad

1. Cloud deploy — `migrate_module_ids.php` entrypoint mein khud chalti hai.
2. Node par app kholein (installer bhi ab yeh migration chalata hai),
   ya haath se: `php scripts/migrate_module_ids.php`
3. Node par dashboard -> **Check** -> "Module IDs match" sabz hona chahiye.
4. Node par **Sync now**.
5. Cloud console par tasdeeq: `permissions royal-grill`

---

# V62.3 — Super admin login, aur ek khatarnak migration

## Kya waqai hua tha

`/session-diagnostics.php` ne tasveer saaf kar di:

    verdict            : OK
    cookie_matches_id  : true
    restaurant_user    : counter1@gmail.com     <- restaurant login CHAL RAHA hai
    super_admin        : null                   <- sirf platform login fail

Yani session, CSRF aur restaurant login sab theek the. Sirf **super
admin ka password match nahi kar raha tha** — `superLogin()` ka
`password_verify()` false de raha tha.

`Platform::ensureSuperUser()` account SIRF tab banata hai jab
`platform_users` mein ek bhi SUPER row **na ho**. Row maujood ho aur
password bhool jaye (ya `sa-password-change` se badal chuka ho) to
wapas aane ka **koi rasta hi nahi tha**.

## Fix 1 — `scripts/reset_super_admin.php`

    php scripts/reset_super_admin.php
        -> maujooda platform accounts ki list

    php scripts/reset_super_admin.php --email="you@x.com" --password="NayaPass@123"
        -> password reset + status ACTIVE + role SUPER

    ... --create
        -> us email ka account na ho to naya bana deta hai

`status='ACTIVE'` bhi set karta hai: `superLogin()` sirf ACTIVE rows
dekhta hai, is liye SUSPENDED account bilkul "ghalat password" jaisa
lagta tha.

## Fix 2 — "Invalid platform credentials" ab rasta batata hai

Wo message be-maani tha. Ab (hifazat ke liye yeh phir bhi nahi batata
ke email ghalat thi ya password):

    Email ya password ghalat hai. Is server par 1 platform account
    maujood hai. Password bhool gaye hain to server par chalayein:
    php scripts/reset_super_admin.php

Aur agar koi ACTIVE platform account hai hi nahi, to `--create` wali
poori command bana kar deta hai.

## Fix 3 — V62.2 ki migration KHATARNAK thi (mera bug)

`scripts/migrate_module_ids.php` merge path par yeh chalati thi:

    DELETE FROM platform_modules WHERE id=?

aur aakhir mein ek blanket `DELETE pm FROM platform_modules ...`.

Agar `role_modules.module_id` ya `user_module_access.module_id` par
`ON DELETE CASCADE` FK ho, to wo ek DELETE **saari permission rows uda
deti**. Har non-admin user ke modules khatam, aur `router.php` har page
par 403 "Access denied" — jo bilkul "login nahi ho raha" jaisa lagta hai.

**Ab yeh migration kabhi DELETE nahi karti.** Duplicate rows sirf
`is_active=0` hoti hain. Ek migration ka kaam data theek karna hai,
mitana nahi.

## Testing — is dafa ASAL mein chala

Is round mein sandbox par PHP install ho gaya, is liye:

    php -l  (har PHP file)          -> 0 errors
    php tools/check_pages.php       -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures

Abhi bhi NahI chala (MySQL sandbox mein socket auth par atak gaya):
`tools/sync_suite.py`, `tools/reset_verify.py`.

## V62.3a — Railway par shell ke baghair super admin reset

`railway run` aur `railway shell` **aap ke apne computer** par chalte
hain, Railway ke container par nahi — yeh sab se aam ghalti hai.
Container ke andar `railway ssh` chahiye.

Magar CLI setup ke baghair bhi rasta hona chahiye. Ab `docker-entrypoint.sh`
mein:

    SUPER_ADMIN_EMAIL     = super@admin.local
    SUPER_ADMIN_PASSWORD  = <naya password>

Yeh dono Railway ki service Variables mein daal kar redeploy karein —
boot par login khud reset ho jata hai. **Reset ke baad dono variables
hata dein**, warna har deploy par password dobara set hota rahega.

Agar yeh variables set na hon, to boot par sirf platform accounts ki
LIST log mein chhap jati hai (koi password nahi) — taake pata chale ke
login kis email se karna hai.

---

# V63 — Phase 1: Settings, do taxes, khali naya business

Aath features ka pehla phase. Sirf yeh teen cheezein — baqi system ko
haath nahi lagaya.

## 1. Settings page JHOOTA tha

`settings.html` **100% localStorage** par chal raha tha:

    var KEY='urban_spoon_settings_v1';
    localStorage.setItem(KEY, JSON.stringify(o));
    toast('Settings saved')

Server par kuch nahi jata tha. Values hardcoded demo thin — "Urban Spoon",
"Islamabad — F10", NTN "1234567-8". User "Save changes" dabata, sabz toast
aata, aur doosre computer par kholte hi wapas wahi demo data.

**Ab har field ki asli jagah hai:**

| Field | Kahan se |
|---|---|
| Restaurant name | `tenants.display_name` |
| Branch | `sites.name` |
| Phone / Address | `sites.phone` / `sites.address_text` |
| NTN / STRN | `organizations.tax_no` |
| Currency, rounding, receipt, operations | `site_settings` |

`site_settings` table schema mein **pehle se maujood** thi (tenant+site
scoped, `NEVER_WIPE` safe) — naya table nahi banaya.

Naya: `src/Services/SettingsService.php`, endpoints `settings-get` /
`settings-save` (Admin/Manager only). Sirf padhne ki ijazat ho to form
khud lock ho jata hai — warna user bharta rehta aur save par 403 milta.

Aur ab client **jawab check karta hai**. Pehle wahan koi check tha hi nahi.

## 2. Do taxes — cash aur card

Settings mein ab:

- **Cash payment tax %**
- **Card / online payment tax %**
- **Service charge %**

Yeh alag copy NahI hain — seedha `ui_records / pos_settings` par jate hain,
**wahi record jo POS parhta hai**. Do jagah rakhne ka matlab hota: Settings
kuch aur dikhaye, POS kuch aur charge kare, aur farq mahinon nazar na aaye.

`FBR POS mode` wala dropdown hata diya — wo naqli tha (kuch nahi karta
tha). FBR ka apna page Phase 4 mein banega, aur cloud par dikhega hi nahi.
`Offline mode` dropdown bhi hataya — wo config se aata hai, page se nahi.

## 3. Naya business ab KHALI banta hai

`Platform::ensureSiteDefaults()` se yeh demo data hata diya:

- 4 menu categories (Pakistani / Fast Food / BBQ / Beverages)
- Main Floor + 8 tables
- 6 expense categories
- "Main Kitchen" printer

Naya customer login kar ke doosre restaurant ka menu dekhta tha, aur uska
pehla kaam us demo data ko delete karna hota tha.

**Yeh teen abhi bhi seed hote hain** — demo nahi, system reference data:

| | Kyun lazmi |
|---|---|
| `units` (PCS, G, KG, ML, L) | inke baghair inventory item **ban hi nahi sakta** |
| `payment_methods` (Cash, Card, Raast...) | inke baghair POS bill **close nahi kar sakta** |
| `stock_locations` (Store, Kitchen) | inke baghair purchase **receive nahi hoti** |

Yeh `factory reset FULL` par bhi lagu hai (wahi function chalta hai).

## 4. FBR spec mehfooz — `docs/FBR_SPEC.md`

Reference ke liye ek purane C# POS ka code dekha gaya, magar spec **poori
tarah hamari apni terms mein** likha hai: hamari tables (`orders`,
`order_items`, `menu_items`, `fiscal_invoices`, `site_settings`), hamare
endpoints, hamare labels.

Us software ke control names, column names, screen labels aur workflow
**kuch bhi hamare system mein nahi aayega**. Us se sirf ek cheez li gayi
hai: **FBR ke apne API ka contract** (`POSID`, `USIN`, `PCTCode` waghera)
— wo FBR ka tay kiya hua hai, usay badla nahi ja sakta.

Do bug jo purane code mein hain aur hum nahi dohrayenge:

1. **Response parsing fixed byte offsets par** — `Substring(18,30)` phir
   pehle `"` tak. Format zara sa badla to galat number ya crash.
2. **`catch (Exception ex) { }`** — FBR fail ho to bill khamoshi se bina
   FBR number ke chhap jata hai. Hamare yahan bill nahi rukega (customer
   khara hai) magar bill par `FBR: PENDING` likha aayega, entry queue mein
   jayegi, retry hoga, aur dashboard par pending count nazar aayega.

Tax formula customer ki hidayat par apna: har line ka tax alag nikal kar
jama, header usi jama se banta hai (alag se dobara nahi ginta) — isi se
wo mismatch khatam hota hai jo purane mixed rounding se paida hota tha.

## Testing

    php -l  (har PHP file)          -> 0 errors
    php tools/check_pages.php       -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + JS)    -> 0 failures

NahI chala (sandbox MySQL socket auth par atka): `sync_suite.py`,
`reset_verify.py`.

## Aage

Phase 2 — roles add/edit + printers (asli `printers` table, category
mapping, final bill par bhi printer-wise grouping).
Phase 3 — reports. Phase 4 — discounts, phir FBR.

## V63.1 — Launcher: toota hua default browser

**Alamat:** naye PC par software chala, magar Firefox ne
`Couldn't load XPCOM` dikhaya. User ko laga software nahi chala —
halanke console saaf keh raha tha:

    Local database ready (127.0.0.1:3307)
    The software is running at http://localhost:8080

**Wajah:** launcher mein sirf yeh tha —

    Start-Process "http://localhost:$port/login.html"

Yani system ka **default browser**. Us PC ka Firefox toota hua tha.
Hamare software ka masla nahi, magar user ke liye farq karna namumkin.

**Fix (`tools/start_offline.ps1`):**
default try → Edge → Chrome → aur har soorat mein address saaf screen par
taake user khud khol sake. Aakhir mein hidayat bhi ke koi doosra browser
khol kar `http://localhost:<port>` likhein.

**Doosra fix:** auto-sync ka message pehle yeh tha —

    Auto-sync could not start (data will sync when it does)

Be-maani. Ab asli exception, log ka path, aur saaf batata hai ke cloud se
data aayega/jayega NahI aur dashboard par "Sync now" se haath se chala
sakte hain.

---

# V64 — Bill templates, 1-minute sync, FBR

## 1. Sync ka asal masla — teen alag raftaarein

Shikayat: *"local par jo kaam karta hun wo online par thora late update
hota hai... kisi aur screen par jane se sync disconnect na ho."*

Wajah ek nahi, teen thin:

| Kahan | Kitni der |
|---|---|
| `sync_loop.php` (asli background process) | **5 minute** (`interval_minutes => 5`) |
| Dashboard ka JS timer | 2 minute |
| POS ka JS timer | 2 minute |

Aur JS timer ka asli aib: **har page load par sifar se shuru hota tha.**
POS -> dashboard -> menu -> POS... har navigation par reset. Agar user har
90 second mein screen badalta to JS wala sync **kabhi chalta hi nahi tha**.
Sirf 5-minute wala loop bacha, aur wo naye PC par chala hi nahi tha
("Auto-sync could not start").

### Fix — char cheezein

1. **Bill close hote hi push.** `pos-finalize` ke baad `sync_nudge()` —
   `register_shutdown_function` se, yani jawab bhejne ke BAAD. Bill ka
   rasta kabhi nahi rukta. Bill ab seconds mein cloud par.
2. **Loop 60 second.** Nayi key `sync.interval_seconds => 60`. Purani
   `interval_minutes` fallback ke taur par chalti rahegi.
3. **Loop OS process hai, page nahi.** Screen badalne se koi taluq nahi.
   Dashboard aur POS ke JS timers ab sirf **status chip** update karte
   hain (20s), sync ka kaam nahi karte.
4. **Watchdog.** Launcher har 30 second dekhta hai ke loop zinda hai
   (`storage/logs/sync_loop.pid`); mara ho to dobara chala deta hai.
   Loop `sync_loop.beat` mein heartbeat bhi likhta hai.

## 2. 80mm bill templates

Pehle bill ka layout `api.php` ke `bill-pdf` case mein **hardcoded** tha —
ek hi shakl, aur Settings ki header/footer lines bill par aati hi nahi thin.

Naya `src/Services/BillTemplate.php` — teen 80mm layouts:

| | |
|---|---|
| **Classic** | poora business info, item + rate, tax breakup, footer |
| **Compact** | ek line per item, kam kaghaz |
| **Tax / FBR** | har line par tax rate + amount, neeche FBR invoice no + QR ki jagah |

Settings mein dropdown + **Preview bill** button. Preview aur asli bill
**ek hi function** se bante hain — do alag copies nahi, warna waqt ke saath
farq aa jata.

Templates ki list server se aati hai (`bill-templates`), taake naam ek hi
jagah rahen.

## 3. FBR — Settings mein hi

Aap ne kaha alag page nahi, Settings mein. Naya section **FBR / Digital
Invoice**: Provider (NONE/FBR/KPRA) · POS ID · Fiscal service URL · NTN ·
Access key · Prices include tax · Default PCT · POS fee · **Test
connection** · **Retry pending**.

`site_settings` group `fiscal` mein.

**Cloud par yeh section apne aap band** ho jata hai, saaf wajah ke saath:
fiscal service aur printers localhost par hote hain, Railway se un tak
pohancha nahi ja sakta.

### `src/Services/FiscalService.php`

**Do usool:**

1. **Sirf offline.** `cfg('app.role')==='cloud'` par sab band.
2. **Bill kabhi nahi rukta.** FBR band ho, net na ho — bill chhapega. Bas
   us par `FBR: PENDING` likha aayega, entry `fiscal_invoices` mein
   queue hogi, aur Retry se jayegi. Khamosh nakami kabhi nahi.

**Tax ka hisaab (aap ki hidayat par apna):** har line ka sale/tax/total
alag, phir header **unhi ka jama**. Header alag se dobara nahi ginta —
isi se wo mismatch khatam hota hai jo per-line rounding se paida hota hai
aur jis par FBR invoice reject karta hai.

Rate wahi `tax_cash` / `tax_card` jo V63 se Settings par hain — payment
method ke `method_type` se chuna jata hai. Alag fiscal rate nahi.

**Response:** asli JSON parse (`InvoiceNumber` / `FBRInvoiceNumber` ...).
Fixed byte offsets ya substring hacks nahi.

`scripts/migrate_fiscal.php`: `orders.fiscal_invoice_no`,
`orders.fiscal_status`, `menu_items.pct_code`, `fiscal_invoices` table.
Har `information_schema` query par lowercase alias.

## Testing

    php -l  (har PHP file)          -> 0 errors
    php tools/check_pages.php       -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + JS)    -> 0 failures
    PowerShell brace/paren/quote balance -> OK

**NahI chala:** `sync_suite.py`, `reset_verify.py` (sandbox MySQL),
PowerShell launcher (sandbox Linux hai), aur **FBR ka asli submission** —
mere paas fiscal service nahi hai.

## V64.1 — Offline install: purani Visual C++ runtime

**Alamat (naye PC par):**

    [2/5] Preparing PHP runtime...
    php.exe : PHP Warning: 'C:\Windows\SYSTEM32\VCRUNTIME140.dll' 14.0
    is not compatible with this PHP build linked with 14.29
    Setup did not complete.

Aur DIAGNOSE.bat mein:

    ext: openssl    : MISSING
    ext: mbstring   : MISSING
    ext: pdo_mysql  : MISSING
    ext: zlib       : MISSING
    Application boot: FAILED

**Wajah:** us PC ki System32 mein purani Visual C++ runtime hai (14.0 =
VC++ 2015). Hamari PHP 8.2 build ko 14.29+ chahiye (VS2019/2022). Is liye
`php.exe` chalta hi nahi.

Wo chaar "MISSING" aur "boot FAILED" **isi ka nateeja** thin, koi alag
masla nahi — magar report se lagta tha ke package hi kharab hai. Asli
wajah `PHP version` wali line mein dabi hui thi.

### Fix — Windows par kuch install kiye baghair

Windows kisi exe ki DLL dependencies **pehle usi folder** mein dhoondta
hai jahan exe hai, phir System32 (`vcruntime140.dll` "KnownDLL" nahi hai).
Chunanche nayi DLL ki copy `php.exe` ke saath rakh dene se System32 ki
purani copy be-asar ho jati hai — aur hamara waada bhi bacha rehta hai:
*"Nothing is installed on Windows."*

**`tools/fix_vcruntime.ps1`** (naya) teen raste, isi tarteeb se:

1. Package ke apne bundled DLLs — `vendor/vcruntime/`
2. PC par kahin maujood nayi copy (System32, Visual Studio folders) dhoond
   kar — version 14.29+ ki tasdeeq ke saath
3. Warna: saaf hidayat ke `vc_redist.x64.exe` install karein

**`install_offline.ps1`:** ab is error ko pehchanta hai, fix chalata hai,
aur **ek dobara koshish** karta hai. Pehle sirf PowerShell ka
RemoteException ka dhair chhapta tha jis se customer ko kuch samajh nahi
aata tha.

**`diagnose.ps1`:** ab asli wajah upar hi likh deta hai —
*"php.exe chal nahi raha, neeche wali saari MISSING lines isi ka nateeja
hain, package bilkul theek hai"* — aur hal ka link deta hai.

**Package mein:** `fix_vcruntime.ps1` aur `vendor/vcruntime/*.dll` ab
offline ZIP mein jate hain (`offline-package` endpoint).

### Ek dafa ka kaam — aap ke liye

Server par `vendor/vcruntime/` mein yeh teen files rakh dein (kisi bhi
aise Windows se jahan VC++ 2015-2022 x64 hai, System32 se):

    vcruntime140.dll   vcruntime140_1.dll   msvcp140.dll

(Properties -> Details -> File version 14.29 ya ziada honi chahiye.)

Uske baad har package mein khud chali jayengi aur yeh masla kisi customer
ke PC par dobara nahi aayega. Folder khali ho to bhi setup chalta rahega —
bas us PC par khud dhoondega, aur na mile to customer ko batayega.

---

# V64.2 — Bill ki alignment, asli QR, FBR panel

## 1. Bill ki alignment — do bugs

**(a) Courier ki chaurai ghalat.** `Pdf::receipt()` mein
`$w = $size * 0.5 * strlen($text)` tha. Courier monospace ki asli chaurai
**0.6** hai. Is liye har centered aur right-aligned line thori bayen
khisak jati thi.

**(b) Ek hi bill par do alag font sizes se column bante the.** `row()`
kabhi size 8 par, kabhi 9 par. Monospace mein alag size = alag chaurai,
is liye raqamein kabhi bhi dayen kinare par nahi aati thin — bill par
"950" beech mein latka hua nazar aata tha.

Ab: `Pdf::cols()` asli columns batata hai (80mm par size 9 = **37**), aur
har column-aligned line **sirf size 9** par banti hai. Sizes ab sirf
centered headings ke liye badalte hain.

Ek aur: `row()` `trim($left)` karta tha — is se item ka indent bhi ur
jata tha. Ab `rtrim()`.

## 2. Address DO DAFA chhap raha tha

Branch ka naam, address aur receipt header — teenon mein wahi matn para
tha, aur code bina dekhe teenon chhap deta tha.

Ab `head()` har line ka matn pehle dekhta hai; jo pehle chhap chuka wo
dobara nahi chhapta. Saath hi lambi lines ab font ke hisab se wrap hoti
hain (kaghaz se bahar nahi jatin), aur `DINE_IN` ki jagah `Dine In`.

## 3. FBR ka QR — ab ASLI, aur offline

**Masla:** POS `https://api.qrserver.com` (INTERNET) se QR ki image
mangwata tha, aur nakami par `onerror="this.remove()"` — yani net band
hote hi QR **khamoshi se gayab**. Offline POS aur FBR bill par yeh
na-qabil-e-qabool hai. Bill PDF par to QR tha hi nahi, sirf `[ QR ]`
likha aata tha.

Aur QR ka payload bhi banaya hua matn tha (`INV:...|AMT:...|TAX:...`),
asli FBR invoice number nahi — jise FBR ka koi scanner pehchanta hi nahi.

**Hal:** `src/Services/Qr.php` — apna QR encoder, koi library nahi:
byte mode, ECC level M, version 1-10, aath masks mein se behtareen.
`Pdf::receipt()` ab QR ko vector murabbon se banata hai (thermal printer
par saaf), aur naya `qr` endpoint POS ke liye SVG deta hai — sab kuch
isi computer par, internet ki koi zaroorat nahi.

QR ab **asli FBR invoice number** ka banta hai (`pos-finalize` ke jawab
mein `fbr_no` aata hai). FBR pending ho to POS par usi waqt toast, aur
bill par `*** FBR: PENDING ***` — mahine ke aakhir mein pata nahi chalega.

### Yeh kaise tasdeeq hua

Encoder likh dena kaafi nahi tha, is liye:

1. **Reference se muqabla** — Python `qrcode` library se matrix
   muqabla. Do asli bug mile aur theek hue:
   - `reserve()` timing modules (6,8) aur (8,6) ko sifar kar deta tha
   - format info bits **ULTI tarteeb** mein lag rahe the (bit 0 par bit
     14 aana chahiye tha)
2. **Asli scanner se** — OpenCV `QRCodeDetector` se 9 mukhtalif payloads:
   **9/9 theek parhe gaye**
3. **Poora PDF bana kar** — bill PDF render (`pdftoppm`, 300 dpi) kar ke
   us tasveer se QR scan: **decode ho gaya, matn bilkul theek**

(Reference library se 3/7 matrices hu-ba-hu match karti hain; baqi mein
sirf mask ka farq hai — dono valid QR hain, jaisa scanner test se sabit
hua.)

## 4. FBR panel container se bahar nikal raha tha

Panel `</main></div>` ke **baad** daal diya gaya tha, yani layout ke
container se bahar. Ab baqi sections ke saath `.content` ke andar hai
aur uska panel-head bhi doosre panels jaisa (`<h2>`).

---

# V65 — Phase A: English, login URL, About, licence expiry

## 1. Login URL (#8)

`router.php` line 26:

    header('Location: /login.html?build=v14');

Yehi `?build=v14` address bar mein nazar aata tha. Ab saaf `/login.html`.
(Baqi `?b=v14` sirf CSS/JS par hai — browser cache ke liye, address bar
mein nazar nahi aata, is liye wo waise hi hain.)

## 2. Poora software English (#12)

29 files mein tehqeeq ke baad **124 user-facing Roman Urdu strings**
mile (`fail()`, `toast()`, `RuntimeException`, `'message'=>`). Sab
English kar diye — error messages, confirmations, bill ka matn, Settings
ke notes, POS ke toasts.

Bara hissa in mein se **meri apni ghalti** thi: V62-V64 mein jo naye
messages daale wo Roman Urdu mein the.

Ab baqi: **0** user-facing strings.

> **Code comments Roman Urdu mein hi rakhe hain** — wo customer ko kabhi
> nazar nahi aate aur aap ke liye parhna asaan rehta hai. Agar aap chahein
> to wo bhi English kar dun, bata dein.

Is doran ek bug bhi bana aur pakra gaya: `$u['full_name'].' 's password'`
— apostrophe ne PHP string tor di thi. `php -l` ne foran pakar liya, aur
maine poore project mein isi qism ki doosri jagahein bhi check kar leen
(koi aur nahi thi).

## 3. About Wabwar (#2)

Sidebar ke neeche naya **ⓘ** button — har page par. Dialog mein:

    Wabwar Software House
    Waseem Iqbal
    +92 342 5095104        (tap-to-call)
    support@wabwar.pk      (tap-to-email)
    www.wabwar.pk
    Build

Naya endpoint `about`. Sirf `shared.css` ki mojooda classes
(`.modal .dialog .dialog-head/body/foot .btn .close`).

## 4. Licence expiry (#11, #13)

**Masla:** expiry sirf CLOUD ke super-admin console mein thi. Restaurant
ka apna software — aur khaas kar OFFLINE node — ko kuch pata hi nahi hota
tha. Customer ek din aata aur software band, koi warning nahi, aur yeh
bhi maloom nahi ke kis ko phone kare.

**Naya `src/Services/Licence.php`:**

- Cloud par seedha `tenant_subscriptions` se
- Offline node par: **har sync ke saath** cloud se licence aata hai aur
  `site_settings` (group `licence`) mein mehfooz hota hai — is liye net
  band ho tab bhi POS ko expiry ka pata rehta hai
- Naye endpoints: `licence-status`, `sync-licence`

**Dikhta kahan hai:**

- **3 din pehle** se peela banner: *"Your subscription expires in 2 days"*
- **Expire hone par** laal banner + Wabwar ka rabta + **Call** aur
  **Email** ke buttons — customer seedha call kar ke renew karwa le
- Dashboard aur baqi pages par `shell.js` se; **POS par alag se**
  (POS `shell.js` use nahi karta) — cashier ko wahin nazar aata hai

## Testing

    php -l  (har PHP file)             -> 0 errors
    php tools/check_pages.php          -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures
    user-facing Roman Urdu strings      -> 0 (pehle 124)

**NahI chala:** licence banner ka asli waqt par chalna (expiry date set
kar ke dekhna parega), `sync_suite.py`, `reset_verify.py`.

## Aage — Phase B

Printers: IP ping se verify + test print (#1), aur category-printer
mapping. Uske baad Phase C reports.

---

# V66 — Support popup, licence control, Phase B (printers)

## 1. Support button (About ki jagah)

Sidebar mein ab **Support** button hai (pehle "About"). Rabte ka naam
sidebar se hata diya gaya. Popup animated hai (slide-up + pulsing badge)
aur usmein sirf rabte ki tafseel:

    Call us   +92 342 5095104     (tap to call)
    Email     support@wabwar.pk
    Website   www.wabwar.pk
    Wabwar Software House · build

Sirf `shared.css` ki mojooda classes + `#supCss` ka apna chhota style
block (scoped, kisi doosri cheez par asar nahi). Licence banner ka
"Details" button bhi ab isi popup ko kholta hai.

## 2. Licence control — har customer ke liye (super admin)

Pehle renewal browser ke `prompt()` se hoti thi:

    prompt('New expiry date (YYYY-MM-DD):')
    prompt('Payment amount (PKR, 0 = no payment record):')

Na validation, na suspend/activate, na yeh nazar aata tha ke abhi kitne
din bache hain.

**Ab proper dialog** (`sa-licence-get` / `sa-licence-set`):

- Abhi ki halat upar: status pill, expiry date, **days left**
- **Extend by**: 1 / 3 / 6 months ya 1 year — mojooda expiry se aage
  (guzar chuki ho to aaj se), ya exact date
- **Status**: Active / Suspended
- Payment amount (0 = koi payment record nahi)
- Server side validation: date format, guzri hui date reject, status
  sirf ACTIVE/SUSPENDED
- Har tabdeeli audit log mein

Yeh wahi licence hai jo V65 ka banner parhta hai — customer ko 3 din
pehle warning, aur expire hone par Wabwar ka number.

## 3. Phase B — Printers (#1)

### Printers page ab ASLI table par

`printers` module `ui_records` mein likhta tha, jabke POS asli `printers`
table se KOT bhejta hai. **Yani yahan banaya hua printer kabhi print
karta hi nahi tha.** Ab dono ek hi table par (`ModuleBridge`).

Naye fields: connection (NETWORK / WINDOWS), IP, port, Windows printer
name, paper width, default printer.

### "Status" ka jhoota dropdown khatam

Pehle Online/Offline ka dropdown tha jise **user khud** set karta tha.
Woh raye thi, haqeeqat nahi — printer band pare hone par bhi "Online"
likha rehta tha aur bill kho jata tha. Hata diya gaya.

### Check + Test print (asli)

`src/Services/PrinterService.php`:

- **Check** — printer ke port par TCP connect (9100 raw / jo set ho).
  TCP ko ICMP ping par tarjeeh di gayi: ping ka jawab dena yeh sabit
  nahi karta ke printer PRINT bhi kar sakta hai; port khula hona zyada
  kaam ka jawab hai.
- **Test print** — asli ESC/POS test page bhejta hai (double-height
  heading, printer ka naam/type/address/waqt, partial cut). Kaghaz
  nikalta hai to sab theek.

Jawab hamesha wajah ke saath:

| Status | Matlab |
|---|---|
| `ONLINE` | port ne jawab diya (+ kitne ms mein) |
| `PORT_CLOSED` | network par hai magar port band — port setting dekhein |
| `NO_RESPONSE` | jawab nahi — printer/IP/port check karein |
| `UNREACHABLE` | ping ne bhi jawab nahi diya |
| `NO_IP` / `BAD_IP` | IP set hi nahi / ghalat |

**Ek ahem baat:** `ping` har jagah maujood nahi hota (kuch systems par
`exec` band hota hai). Aisi soorat mein ab hum yeh **dawa nahi karte**
ke "printer network par hai hi nahi" — sirf `NO_RESPONSE` kehte hain.
Pehle wala code ping na milne par bhi UNREACHABLE keh deta, jo bilkul
gumrah-kun hota.

### Kitchen routing UI

POS pehle se printer ke hisab se KOT group karta hai (`PosService`),
magar **yeh mapping set karne ka koi UI tha hi nahi**. Ab Printers page
par "Kitchen routing" — har menu category ke saamne printer ka dropdown.
Isi se multi-category bill ke alag alag KOT sahi printers par jate hain.

### module.js — rowActions

Modules ab apne extra row buttons de sakte hain (`rowActions`). Printers
ke "Check" aur "Test print" isi se hain, aur **jawab check hota hai** —
kamyabi ya nakami, poori wajah ke saath user tak.

## Testing

    php -l  (har PHP file)              -> 0 errors
    php tools/check_pages.php           -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures

**PrinterService chala kar test kiya:**

    listening port par     -> ONLINE (0 ms)      <- asli socket khol kar
    band port par          -> NO_RESPONSE
    ghalat IP par          -> BAD_IP
    IP hi na ho            -> NO_IP

**NahI chala:** asli printer par test print (mere paas printer nahi),
licence banner ka waqt par chalna, `sync_suite.py`, `reset_verify.py`.

---

# V67 — Phase C: Reports (+ URL fix)

## 1. `?build=v14` — asli jagah mili

V65 mein maine sirf `router.php` ka redirect theek kiya tha, magar
customer ko wo URL **login ke baad** dikhta tha. Asli jagah
`login-submit.php` thi — chhe redirects mein:

    header('Location: /index.html?build=v14', true, 303);
    header('Location: /login.html?login_error=invalid&build=v14'.$bq);

Sab saaf kar diye. `reset-demo-ui.php` mein `?build=v13` bhi tha, wo bhi.
Ab address bar mein sirf `/index.html` aur `/login.html` aata hai.

## 2. Reports (#3) — 15 reports

`reports.html` ek khali shell tha: chart ka CSS mojood, data koi nahi.
Numbers `localStorage` ke seed se aate the — khoobsurat, magar customer
ke apne karobar se un ka koi taluq nahi.

Naya `src/Services/ReportService.php`:

| Group | Reports |
|---|---|
| **Sales** | Sales summary · Sales by item · Sales by category · Sales by hour · Payment methods · Dine-in/takeaway/delivery |
| **Tax** | FBR / fiscal sales · Tax summary |
| **Money** | Expenses · Profit and loss |
| **Operations** | Sales by cashier · Sales by table · Voids and discounts |
| **Inventory** | Stock movement · Purchases |

**Design ke do usool:**

1. **Har report ek hi shakl lautati hai** — `{columns, rows, totals}`.
   Is liye ek hi UI sab dikhati hai aur CSV export bhi ek hi jagah likha
   hai. Har report ka apna page banate to har naye report par teen jagah
   code likhna parta.
2. **Har raqam DATABASE se aati hai**, JS mein hisab nahi hota. Bill ke
   total aur report ke total ka farq hi wo cheez hai jis se customer ka
   software par se etimad uth jata hai.

VOID bills har report se khaarij hain — warna sale asli se zyada dikhti.

**Profit & loss** cost of sales recipe consumption se leta hai. Agar
recipes nahi banayi gayin to cost sifar aayega aur profit zyada dikhega —
report khud yeh baat likh kar batati hai, chupati nahi.

CSV export har report par (Excel ke liye BOM ke saath).

### Testing — SQL bina chalaye nahi diya

Sandbox mein MySQL start nahi ho saka (socket auth). Is liye maine
**har query ko schema ke khilaf column-by-column verify** kiya —
`docs/02_local_mysql_schema.sql` + migrations se 96 tables ke columns
nikal kar, har `alias.column` reference match kiya.

Pehle pass mein **ek asli bug pakra gaya**:

    stock_transactions.created_at  -> is table par yeh column hai hi nahi

Sahi column `business_date` hai (aur indexed bhi). `goods_receipts` par
bhi `created_at` ki jagah `received_at` behtar tha. Dono theek.

Dosre pass mein: **15 queries, 0 problems.**

    php -l  (har PHP file)              -> 0 errors
    php tools/check_pages.php           -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures

**NahI chala:** reports asli data par (MySQL sandbox mein nahi chala).
Column names verify ho chuke hain, magar natije ki tasdeeq aap ke data
par hi hogi — khaas kar Profit & Loss.

## Aage

Custom report builder (C2), phir backup (#10) aur help desk (#9).

---

# V68 — Tablet on the LAN

## Asli rukawat: server LAN par sunta hi nahi tha

    php -S 127.0.0.1:8080 -t public public/router.php

Offline server **sirf `127.0.0.1`** par sunta tha — yani sirf usi
computer se khulta tha. Tablet chahe usi WiFi par ho, us tak **pohanch
hi nahi sakta tha**.

Tablet ka baqi saara kaam pehle se bana hua tha (444-line UI,
`order_taker_db.js` asli `pos-boot` / `pos-kot` par, `paired_devices`
table, QR pairing, role limiting). **Sirf darwaza band tha.**

## Kya kaam kiya gaya

**1. LAN binding** — `127.0.0.1` → `0.0.0.0`. Port check bhi
`IPAddress::Any` par. Hifazat kam nahi hui: `router.php` har
non-public page par login/pairing maangta hai, sirf `login.html`,
`pair.html`, `qr.html` khule hain.

**2. Firewall** — installer ab ek dafa inbound rule banata hai
(TCP 8080), **sirf Private network par**. Public profile par
jaan-boojh kar NAHI — warna cafe ya airport ki WiFi par POS khul jata.
Admin rights na hon to setup saaf batata hai ke tablets tab tak
connect nahi honge.

**3. LAN address har start par** — router aksar PC ko nayi IP de deta
hai aur agle din tablet chalna band kar deta hai. Ab launcher har baar
asli IP dhoond kar dikhata hai aur `storage/logs/lan_url.txt` mein
likh deta hai:

    TABLETS / OTHER DEVICES ON THIS WIFI
       http://192.168.1.10:8080

**4. "Connect a tablet"** — POS par naya button. Ek screen par: LAN
address, **QR code**, aur 15 minute ka pairing token. QR isi computer
par banta hai (V64.2 wala apna encoder) — internet ki zaroorat nahi.

Waiter QR scan karta hai → `pair.html` (public page) → device paired →
**seedha Order Taker screen**. Na IP likhni parti hai, na password.
Yeh `device-pair-claim` ka mojooda redirect hi hai
(`WAITER → /restaurant_order_taker_tablet.html`).

**5. Tablet ab POS jaisa dikhta hai** — pehle uske apne rang the
(green brand, alag background); ek hi restaurant ke do screens **do
alag software** lagte the. Ab wahi tokens jo `restaurant_pos.html`
use karta hai. Purane CSS variable naam bhi rakhe hain (POS ke rangon
par map kiye) taake mojooda 400+ lines ka CSS na toote.
`pair.html` bhi wahi brand.

**6. POS band = tablet band, magar SAAF paighaam ke saath**

Aap ka faisla: tablet ek patla client rahe. Ab wo har 15 second server
se rabta check karta hai:

| Halat | Kya dikhta hai |
|---|---|
| Server nahi mila (2 dafa lagatar) | "POS computer is not reachable — this tablet works only while the POS computer is switched on" |
| 401 / 403 | "This tablet needs to be connected again — ask the counter to tap Connect a tablet" |

Yeh farq ahem hai: session khatam hone par "POS not reachable" kehna
**jhoot** hota aur waiter be-wajah counter ka computer dekhne chala
jata. Aur ek dafa fail hone par screen nahi dikhati — WiFi ka lamhati
jhatka aam hai.

## Testing

PHP built-in server LAN address par chala kar **asli test**:

    localhost se       -> 302 -> /login.html
    LAN IP se          -> 302 -> /login.html      <- tablet ka rasta, ab khula
    pair.html (public) -> 200                     <- QR scan ke liye
    login page         -> loads

(Redirect mein `?build=v14` nahi hai — V67 wala fix bhi isi test se
tasdeeq ho gaya.)

    php -l  (har PHP file)              -> 0 errors
    php tools/check_pages.php           -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures

**NahI chala:** Windows firewall rule (sandbox Linux hai), asli tablet
par QR scan aur order lena, aur `sync_suite.py` / `reset_verify.py`.

---

# V69 — Module audit, FBR menu, printer form

## 1. Left menu ka poora audit — `docs/MODULE_STATUS.md`

Har page khol kar dekha gaya: data kahan se aata hai, kahan jata hai.
Yeh raye nahi, code se nikali hui haqeeqat hai.

| Halat | Kitne | Matlab |
|---|---|---|
| **WORKING** | 14 | asli DB par — save, sync, reports sab |
| **PARTIAL** | 4 | chalta hai magar kuch hissa adhoora |
| **SHELL** | 15 | sirf khoobsurat screen; data `ui_records` mein para rehta hai, POS/stock/reports se **juda nahi** |
| **DEMO** | 2 | hardcoded naqli data |

**Sab se ahem kami: Kitchen / KDS.** 1,119 lines ka page, magar usme
`const orders=[...]` hardcoded hai. POS asli KOT `kitchen_tickets` mein
bhejta hai — KDS unhen **parhta hi nahi**. Kitchen screen naqli orders
dikha rahi hai.

Doosri qism ki misal: "Running Orders" mein order banayein to POS ko
pata nahi chalta; "Stock Transfer" karein to stock hilta hi nahi.

Poori fehrist aur kaam ki tajweez karda tarteeb doc mein hai.

## 2. FBR left menu se hata diya

Aap ki baat durust thi: FBR ka apna page banta hi nahi. Wo **Sale Point
ke saath juda hua** kaam hai — settings Settings mein hain (V64 se) aur
invoice POS par bill close hote waqt banti hai.

Menu se hata diya. `fbr.html` file rehne di gayi taake purane bookmark
na tootein.

## 3. Printer form chhota

Popup mein 10 fields the — connection type, Windows printer name, paper
width, port, status... jinme se aksar customer ko samajh hi nahi aate
the aur ghalat bhar dete the.

**Ab sirf 5:** Printer name · Type · Location · IP address · Default
receipt printer.

Baqi ki mehfooz defaults server khud lagata hai (port 9100, NETWORK,
paper Settings se, status Active). Purani rows ki mojooda values
mehfooz rehti hain.

## 4. Super admin — modules ki asli halat saamne

Features dialog mein ab har module ke saamne uska **asli tag**:

    Sale Point / POS        [working]
    Inventory               [partial]
    Kitchen / KDS           [demo data]
    Stock Transfer          [screen only]

Aur naya **"Working only"** button — ek click mein sirf wo modules
chunay jate hain jo waqai kaam karte hain.

Faida: jo modules abhi shell hain unhen customer ke menu se band rakha
ja sakta hai. **Adhoora feature dikhna, na dikhne se bura hai** — customer
usay aazmata hai, kaam nahi karta, aur poore software par se bharosa
uth jata hai.

## Testing

    php -l  (har PHP file)              -> 0 errors
    php tools/check_pages.php           -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures

---

# V70 — Char rozana ke modules ab asli tables par

Audit ke mutabiq jo char sab se ahem the, wo char kar diye. Yeh
`ui_records` / demo data se nikal kar asli tables par aa gaye hain.

## 1. Kitchen / KDS — sab se bara masla

`kds.html` 1,119 lines ka poora page hai, magar us mein tha:

    const orders=[ {id:'KOT-0128', bill:'#0024', ...} ]

**Hardcoded naqli tickets.** POS `sendKot()` se asli KOT
`kitchen_tickets` mein bhejta tha — KDS unhen **parhta hi nahi tha**.
Kitchen screen us restaurant ka apna kaam dikhati hi nahi thi.

Naya `public/kds_db.js` (wahi tareeqa jo `order_taker_db.js` mein hai —
page ka UI bilkul nahi chhua):

- Asli `kitchen_tickets` + `kitchen_ticket_items`, har 10 second refresh
- Station ke hisab se (POS pehle se printer-wise group karta hai)
- **Delayed lane asli hai**: 15 minute se purana aur ready nahi
- Start / Ready / Done ab server par jate hain (`kds-status`)
- Nakami par UI **wapas purani halat par** — warna cook samajhta ke
  ticket ready ho gaya jabke database mein kuch nahi badla

## 2. Opening & Closing Shift

`cashier_shifts` table maujood thi, page `ui_records` mein likhta tha.
Cash ka koi hisab hi nahi tha.

Ab asli: shift open (opening float), shift close (counted cash), aur
**expected cash server par ginta hai** — us shift ke doran ke saare
cash payments + opening float. Variance khud nikalta hai:

    Expected in till: 24,500
    You counted:      24,300
    Difference:         -200

**Aur ek zaroori fix:** POS ki screen `shift_id` bhejti hi nahi thi, is
liye har payment `shift_id = NULL` ke saath jata tha aur expected cash
kabhi theek ban hi nahi sakta tha. Ab `PosService` khud cashier ki open
shift dhoond kar bill se jor deta hai.

Aur `payments` par `created_at` column hai hi nahi — `paid_at` hai.
Yeh bhi pakra gaya (neeche testing dekhein).

## 3. Running Orders

Ab POS ke asli khule bills: bill no, mode, table, waiter, items, amount,
aur **kitni der se khula hai** (45 minute se ooper laal). Pehle is page
par banaya gaya order POS ko pata hi nahi chalta tha.

## 4. Void / Refund

Bill VOID karne ka asli kaam `DeleteService::voidOrder()` mein V62 se
maujood tha — yeh page us tak pohanchta hi nahi tha.

Ab pichle 7 din ke bills, aur Void button: wajah + manager password ke
saath. Bill **delete nahi hota** — VOID hota hai, bill number history
mein rehta hai, payments cancel hoti hain aur **stock wapas aata hai**.

## Super admin + audit doc

Chaaron ab `working` tag ke saath. `docs/MODULE_STATUS.md` update:

| Halat | Pehle | Ab |
|---|---|---|
| WORKING | 14 | **18** |
| SHELL | 15 | **11** |
| DEMO | 2 | **1** |

## Testing

SQL bina jaanche nahi diya — har query schema ke khilaf column-by-column
verify ki. **Pehle pass mein ek asli bug pakra gaya:**

    payments.created_at  ->  is table par yeh column hai hi nahi

Sahi column `paid_at` hai. Aur `payments.shift_id` bhi maujood hai — wo
waqt ke muqable mein zyada pukhta rabta hai, is liye query ab pehle
`shift_id` dekhti hai aur sirf uske na hone par waqt par jati hai.

Doosre pass mein: **12 queries, 0 problems.**

    php -l  (har PHP file)                -> 0 errors
    php tools/check_pages.php             -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures

**NahI chala:** yeh sab asli data par (MySQL sandbox mein start nahi
hota). Column names verify ho chuke hain; natije ki tasdeeq aap ke
data par hogi.

---

# V71 — Baqi saare shell modules asli tables par

V70 mein char kiye the. Ab baqi bhi. Kisi bhi module ka data ab
`ui_records` mein nahi jata.

## Kya kaha se aata hai (ab)

| Module | Asli table |
|---|---|
| Discounts / Promotions | `promotions` (+ rules JSON) |
| Reservations | `reservations` |
| Rider Management | `riders` + live jobs `delivery_orders` se |
| Staff | `employee_profiles` + login ka link `users` se |
| Multi-Branch | `sites` + har branch ki aaj ki sale |
| Loyalty / Membership | `customers` + asli visits/spend |
| Stock Transfer | `stock_transfers` + **dono taraf asli stock movement** |
| Physical Stock Count | `stock_count_sessions` + **farq ka asli adjustment** |
| Accounting / Cash | `payments` + `expenses` — rozana cash book |
| Online Orders | `orders` (delivery + QR) + rider |
| WhatsApp / Notifications | `notification_queue` — kya gaya, kya fail hua |

## Do jagah jahan sirf list kaafi nahi thi

**Stock Transfer.** Pehle transfer banayein to **stock hilta hi nahi
tha**. Ab `InventoryService::postMovement()` dono taraf chalta hai —
bhejne wali branch se minus, lene wali mein plus, ek transaction mein.

**Physical Stock Count.** Ab system qty aur counted qty ka farq nikal
kar **asli adjustment** post hota hai. Yani count karne ka matlab yeh
hai ke stock waqai shelf ke barabar ho jata hai — pehle sirf ek record
banta tha aur stock jaisa tha waisa hi rehta tha.

## Loyalty — koi alag table nahi

Tier aur points customer ke **asli kharch** se bante hain
(`orders` se), kisi alag table se nahi. Faida: yeh hamesha sach hota hai
aur purana nahi parta. Gold 100k+, Silver 30k+, points = spend / 100.

## Staff aur login jaan-boojh kar alag

Staff page sirf employee record rakhta hai. **Login banana Users &
Access ka kaam hai.** Do jagah login banane se roles ka nizam toot jata
hai aur yeh pata nahi chalta ke kis ke paas kya ijazat hai.

## Testing — teen asli bug pakre gaye

121 queries schema ke khilaf column-by-column verify kiye (7 services).

    stock_transfer_items.stock_transfer_id  -> asli naam `transfer_id`
    stock_count_items.stock_count_session_id-> asli naam `count_session_id`
                       .counted_qty         -> asli naam `physical_qty`
    FiscalService: payments.created_at      -> asli naam `paid_at`

Aakhri wala ahem hai: **wahi bug FiscalService mein bhi tha** jo V70
mein OpsService mein mila tha. Yani FBR submission ka payment-mode
lookup hamesha fail ho raha hota (aur tax rate ghalat ja sakta tha).
Ab dono jagah theek.

Aakhri pass: **0 problems in 121 queries**.

    php -l  (har PHP file)                -> 0 errors
    php tools/check_pages.php             -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures

## Audit ka natija

| Halat | V69 | Ab |
|---|---|---|
| WORKING | 14 | **30** |
| PARTIAL | 4 | 4 |
| SHELL | 15 | **0** |
| DEMO | 2 | 2 |

---

# V72 — Logout link, tablet rework, guides, custom reports

## 1. Logout ke baad business ka link gum ho jata tha

Login: `login.html?b=akorwal-fish-point` — customer seedha apne business
par. Logout ke baad: sirf `login.html`.

**Wajah:** `Auth::logout()` poori session uda deta tha, jis mein
`login_tenant_slug` bhi chala jata tha.

Ab logout sirf session **badalta** hai (`session_regenerate_id`), aur
slug wapas rakh deta hai. Yeh sirf "kaunsa business" ka nishan hai, koi
hifazati cheez nahi — is liye ise rakhna bilkul mehfooz hai. `logout`
endpoint ab wahi link wapas deta hai aur shell usi par bhejta hai.

## 2. Tablet screen dobara likhi gayi

**Left rail hata diya.** Order taker sirf order leta hai — usay modules
ke icons ki zaroorat hi nahi.

**HOLD BILLS ab asli hai.** Screen khulti hi tables ke grid par: har
dine-in table, us par khula bill, kitne items, kitni der se, aur
**ab tak ka total** — taake customer ke poochne par foran bata sake.
Jis table par kuch bina bheje para ho us par "not sent" ka nishan.

**Koi bhi order taker, koi bhi table.** Table kholte hi **poora bill**
aata hai, sirf apni entries nahi — warna teen waiters ek hi table par
kaam karein to kisi ko poora total pata hi na chale.

**Kis tablet ne kya punch kiya** — `order_items.device_id` aur
`created_by_user_id` naye columns. Pehle `orders.device_id` tha magar
item level par kuch nahi tha, is liye jhagre ka koi hal nahi tha.

**Cash / Card toggle** — total foran badalta hai (`tax_cash` vs
`tax_card`), taake customer ko theek raqam batayi ja sake.

**Design POS jaisa** aur poora responsive: mobile par cart neeche se
uthta hai, safe-area ka khayal, tap targets 36px+.

Naya `pos-hold` endpoint — bill khula rehta hai, kitchen ko kuch nahi
jata. Pehle hold ka server-side rasta tha hi nahi.

`ensureOpenOrder()` ab table se bhi kaam karta hai — pehle `bill_no`
lazmi tha aur khali aane par khali bill number wala order ban jata tha.

## 3. Offline-only cheezein cloud par chhupi

`window.APP_ROLE` ab har page ko maloom hai. POS par "Connect a tablet"
cloud par nazar hi nahi aata — tablet aur printers LAN par hote hain.
Button dikha kar phir "yeh yahan nahi chalta" kehna customer ka waqt
zaya karna hai.

## 4. Har module ki guide — `Guide.php`

**33 modules** ki tafseeli rehnumai, software ke andar. Sidebar mein
naya **?** button. Har guide ek hi shakl mein:

    What    — module karta kya hai
    Steps   — pehli dafa kya karna hai, tarteeb se
    Tips    — wo baatein jo baad mein pata chalti hain
    Careful — wo ghaltiyan jo waqai nuqsan deti hain

Misal (Shift): *"Count first, then enter — do not look at the expected
figure first."* Misal (POS): *"Items already sent to the kitchen cannot
simply be reduced."*

Guide server se aati hai, is liye ek hi jagah likhi hai aur screen ke
saath purani nahi parti.

## 5. Custom report builder

Customer apni marzi ki report bana sake — **magar SQL likhe baghair**.

Khula SQL dena khatarnak hai (ek galti aur poora data jal jaye). Is liye
ek mehdood, mehfooz builder: teen data sources (Sales, Items sold,
Expenses), har ek ki apni grouping (day / month / hour / weekday /
cashier / table / customer / item / category) aur figures (bills, sales,
tax, discount, average bill, quantity...).

**User ka koi harf SQL mein nahi jata** — sab kuch server ki registry se
banta hai. Reports list mein "Your own > Custom report".

## Testing

    php -l  (har PHP file)                -> 0 errors
    php tools/check_pages.php             -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures
    SQL schema check: 321 queries         -> 0 real problems
      (23 flags sab `sync_tombstones` par, jo migration se banti hai)

**NahI chala:** asli tablet par (WiFi + device chahiye), asli printer,
aur MySQL (sandbox mein start nahi hota).

---

# V73 — "Unknown API action", report design, closing report

## 1. Reports khul hi nahi rahi thin — asli wajah

`db_api.js` mein:

    '/api.php?action=' + encodeURIComponent(action)

Aur reports page bhejta tha:

    req('report-run&id=sales_summary&from=2026-01-01&to=...')

`encodeURIComponent()` **poori string** ko encode karta hai, is liye `&`
bhi `%26` ban jata tha aur server ko ek hi ajeeb sa action milta:

    action=report-run%26id%3Dsales_summary%26from%3D...   ->  Unknown API action

**Char jagah** yehi masla tha: `report-run`, `guide`, `shift-expected`,
`tablet-order` — yani reports, module guides, shift ka expected cash aur
tablet ka table kholna, sab.

Ab sirf action ka **naam** encode hota hai, baqi query waise hi jati hai
(uske hisse pehle se encode ho kar aate hain).

## 2. Report section ab ek dastavez hai

Pehle sirf ek nangi table thi — na business ka naam, na date range, na
totals ka farq. Customer usay print kar ke kisi ko de hi nahi sakta tha.

Ab har report ke sar par: **business ka naam, branch, NTN, date range,
kitni rows, kab print hui**. Neeche totals ki alag row. Numbers
tabular-nums mein (columns theek line mein), alternate rows halke
shade mein.

**Naya Print button** + print CSS: sidebar, header, buttons, toast —
print par kuch nahi aata, sirf report. Aur print par
**"Prepared by / Checked by"** ki jagah khud aa jati hai.

CSV export pehle se tha, waisa hi hai.

## 3. Shift closing report — 80mm par

Aap ki baat theek thi: closing report wahi kaghaz hai jo counter par
laga hota hai. Ab wo hamesha **80mm** par chhapti hai, bill ke hi
`Pdf` / `BillTemplate` se — taake shakl aur alignment bilkul ek jaisi rahe.

Report mein:

    SHIFT CLOSING REPORT
    Shift / Cashier / Opened / Closed
    -------------------------------------
    SALES BY CATEGORY
      BBQ                          18,400
        14 x Chicken Tikka         11,200
        10 x Seekh Kabab            7,200
      KARAHI                       14,300
        ...
    -------------------------------------
    Bills 37 / Subtotal / Discount / Tax
    =====================================
    TOTAL SALES                    43,260
    PAYMENTS: Cash / Card
    CASH IN TILL: opening / expected / counted
    =====================================
    CASH SHORT                       -200

    Cashier signature: ______________
    Manager signature: ______________

Bills shift se `shift_id` par jurte hain, aur jin purane bills par
`shift_id` NULL hai un ke liye waqt se — warna purani shifts ki report
khali aati.

Shift close karte hi report **khud khul jati hai**, aur har purani shift
par "Print report" ka button bhi hai.

## Testing

    php -l  (har PHP file)                -> 0 errors
    php tools/check_pages.php             -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures
    URL banane ka test                    -> action=report-run&id=... (theek)
    Closing report render + PDF           -> 4,018 bytes, layout saaf

**NahI chala:** asli printer par 80mm output, aur MySQL par reports ka
data (sandbox mein start nahi hota).

---

# V74 — Reports section ka poora UI

Aap ki baat theek thi: V73 mein maine sirf table sajaayi thi, poora
section wahi purana khaka tha.

## Jo galat tha

- **Page ka apna chart CSS istemal hi nahi ho raha tha.** `.bars-h`,
  `.brow`, `.track`, `.fill` — sab pehle se likha para tha aur screen par
  kahin nazar nahi aata tha.
- **Header ke `.seg` (Today/Week/Month) aur Export button** bhi mojood
  the magar kisi se jure hue nahi the.
- Na KPIs, na chart, na report ka sar-nama. `.kpis` / `.kpi` classes
  design system mein maujood thin, maine use hi nahi ki thin.

## Ab kya hai

**Header** — `.seg` range switcher (Today / Week / Month / Year) ab
waqai kaam karta hai, aur range badalte hi report dobara chal jati hai.
Print aur Export CSV wahin.

**Bayen taraf** — reports ki fehrist, group ke hisab se, sticky. Chuni
hui report par brand rang ki patti.

**Upar** — date range aur "Run report".

**KPI cards** — report ke apne totals se (pehle char numeric columns).
KPI aur table dono ek hi `totals` se aate hain, alag hisab nahi — warna
dono ka number alag ho jata aur bharosa khatam.

**Report ka sar-nama** — bayen taraf report ka naam + date range + rows,
dayen taraf business ka naam, branch, NTN, print ki tareekh. Neeche
kaali line. Yeh ab ek dastavez hai, table nahi.

**Table** — totals ki alag `tfoot` row, numbers `tabular-nums` mein
(columns theek line mein).

**Chart** — pehle numeric column ka horizontal bar chart, top 10. Wahi
CSS jo page mein pehle se para tha. 40 se ziada rows par chart nahi
banta — wahan bekaar lagta hai.

**Khali halat** — sirf khali table ki jagah ab saaf paighaam: "No data
in this period — try a wider date range."

**Print** — sidebar, header, buttons, nav, toast, kuch nahi chhapta;
sirf report. Aur print par **"Prepared by / Checked by"** ki jagah khud
aa jati hai.

**Custom report** builder bhi usi shakl mein — natija form ke neeche
aata hai, form gayab nahi hota.

## Design system

Sab kuch `shared.css` ki mojooda classes se: `.panel`, `.panel-head`,
`.kpis`, `.kpi`, `.seg`, `.table`, `.tag`, `.field`, `.form-grid`,
`.note`, `.btn`. Sirf `rep-*` ke apne chand rules page ke andar
(scoped), aur wo bhi design system ke variables par.

Tasdeeq: page ki 47 classes mein se ek bhi aisi nahi jo `shared.css`
ya page ke apne style block mein defined na ho.

## Testing

    php -l  (har PHP file)                -> 0 errors
    php tools/check_pages.php             -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures
    CSS class audit                       -> 0 invented classes

---

# V75 — Shift close freeze, installer, print, auto-update

## 1. Close shift par popup jam ho jata tha — DO bug

**(a) Maine hi endpoint chura liya tha.** V70 mein maine
`shift-open` / `shift-close` naam se naye endpoints daale — magar POS ke
paas **pehle se** isi naam ke endpoints the (`shift-preview` ke saath).
PHP `switch` **pehla match** chalata hai, is liye POS ka "Close Shift"
mere naye endpoint par ja raha tha jo bilkul alag payload maangta hai.
Shift close hoti hi nahi thi.

Ab Shift Management page `shiftmgr-*` use karta hai; POS ke purane
endpoints jaise the waise hain. Poore `api.php` par duplicate-action
check chalaya — ab ek bhi duplicate nahi.

**(b) Khamosh JS crash.** POS ke close-shift handler mein:

    clear_cash: ov.querySelector('#clrChk').checked ? 1 : 0

`#clrChk` us modal mein hai hi nahi. `null.checked` parhte hi handler
wahin mar jata tha — button "Closing..." par atak jata, koi error nahi,
kuch nahi hota. **Bilkul wahi "freeze" jo aap ne dekha.**

## 2. POS: highlight ab "+ New Bill" par

Cashier din mein sainkron dafa naya bill kholta hai aur "New Item"
kabhi kabhaar. Rang us cheez par hona chahiye jo sab se ziada dabai
jati hai. Dono buttons ki jagah badal di, aur header wale New Bill par
`F1` bhi likha hai.

## 3. Bill par business ka naam nahi aa raha tha

Slip ka header `BOOT.site.name` use karta tha — **wo BRANCH ka naam
hai**, business ka nahi. Isi liye bill address se shuru hota tha.

Ab pehli line business ka naam, phir branch, address, phone, NTN,
receipt header — sab **Settings se** (wahi jagah jahan bill template
chuna jata hai). Footer aur paper size bhi Settings se; pehle sirf
localStorage mein tha, is liye har computer par alag ho jata tha aur
Settings ka koi asar hi nahi hota tha.

QR ka code pehle se tha magar `PRINT.qr` par mashroot — purani
localStorage value se off para ho sakta tha. Ab default ON.

## 4. Offline version ab KHUD update hota hai

Pehle har chhoti tabdeeli par customer ko portal se poora package
download kar ke dobara install karna parta tha. **Har baar.**

- `scripts/self_update.php` — cloud se poochta hai ke naya build hai
  ya nahi (naya `update-check` endpoint). Naya ho to package `updates/`
  mein khud aa jata hai.
- Launcher har start ke 45 second baad background mein yeh chalata hai.
- Naya build tayyar ho to launcher par saaf line: **"A NEW UPDATE IS
  READY"**.
- `INSTALL_UPDATE.bat` usay lagata hai: pehle purani files ka backup,
  phir nayi files, phir migrations.

**Install user ke DABANE par hota hai, khud-ba-khud nahi** — chalte POS
ko beech mein badalna kabhi theek nahi. Aur `data\`, `config\`,
`runtime\`, `storage\` ko haath nahi lagta: customer ka data aur
settings mehfooz.

Adhoori download bhi pakri jati hai (size + ZIP signature), warna aadha
package "ready" ban kar software tor deta.

**Shortcut ka icon** — `public/assets/app.ico` bana diya (brand rang ka
rounded square). Installer mein icon ka code pehle se tha, **file hi
maujood nahi thi**.

## 5. Installer "Remove-Item : Cannot find path" par ruk jata tha

Yeh sirf temp file ki **safai** ka kaam tha. Masla asal mein yeh tha ke
parent script native command ke **stderr ko error samajh kar** poora
setup rok deti thi — halanke PHP bilkul theek install ho chuka hota.

Do fix:

1. `resolve_php.ps1` — temp zip tabhi delete karo jab wo waqai maujood
   ho (`Test-Path -LiteralPath` + try/catch).
2. `install_offline.ps1` — kamyabi ka faisla ab **natije se**, shor se
   nahi: `php.exe` waqai chal raha hai aur `PHP 8` batata hai, tabhi
   aage barho.

## Testing

    php -l  (har PHP file)                -> 0 errors
    duplicate API actions                 -> 0 (pehle 2)
    php tools/check_pages.php             -> PAGE_CHECK_OK files_with_scripts=44
    node --check (44 pages + public/*.js) -> 0 failures
    PowerShell balance (3 scripts)        -> OK
    app.ico                               -> valid ICO, 64x64

**NahI chala:** Windows par installer, asli printer, aur auto-update ka
poora chakkar (uske liye do alag builds chahiyen).

---

# V76 — Shift closing print ab wahi design jo tay hua tha

## Masla

POS ka "Close Shift" apna alag, saada slip chhap raha tha:

    Gross Sales   PKR 58,256
      CASH        PKR 53,806 (5)
      CARD        PKR 4,450 (1)
    Expected Cash PKR 53,806
    Variance      PKR -0

Yeh report thi hi nahi — sirf ek hisab tha. Malik ko yeh nazar hi nahi
aata tha ke us shift mein **bika kya**.

**Wajah:** POS ka print `printNode(#repBody)` chalata tha — yani screen
par jo chand rows dikh rahi thin, wohi kaghaz par chhap jati thin. Jo
80mm design maine V73 mein banaya aur test kiya tha (category-wise sale,
items, payments), POS us tak pohanchta hi nahi tha.

## Ab

POS ka Print aur "Close Shift" dono **wahi 80mm closing report** kholte
hain jo Shift Management page kholta hai:

    SHIFT CLOSING REPORT
    Shift / Counter / Cashier / Opened / Closed
    -------------------------------------
    SALES BY CATEGORY
      BBQ                          24,600
        10 x Chicken Tikka         16,000
         8 x Seekh Kabab            8,600
      KARAHI                       21,400
        ...
    -------------------------------------
    Bills 6 / Subtotal / Discount / Sales Tax
    =====================================
    TOTAL SALES                    58,256
    -------------------------------------
    PAYMENTS
    Cash                           53,806
    Card                            4,450
    -------------------------------------
    CASH IN TILL
    Opening float / Less: cash expenses
    Expected / Counted
    =====================================
    BALANCED                            0

    Note: ...
    Cashier signature: ______________
    Manager signature: ______________

Shift band hote hi report **khud khul jati hai**.

## Report mein teen izafay

- **Counter ka naam** — kai counters wali jagah par kis till ki report
  hai, yeh saaf hona chahiye.
- **Cash expenses** — jo cash isi shift mein counter se nikla. Iske
  baghair variance ghalat lagta hai aur cashier be-wajah shak mein
  aata hai.
- **Closing note** — cashier ne jo likha, report par aata hai.

## Chhota magar zaroori

`shift-close` ab shift ka `id` bhi wapas karta hai (pehle nahi karta
tha), warna POS report khol hi nahi sakta tha. Aur `shiftmgr-report-pdf`
ab POS ke cashier ke liye bhi khulta hai — usay 'shift' module ki ijazat
na ho tab bhi apni shift ki report chhap sake.

Purane build se aaye hue report (jis mein `shift_id` na ho) par purana
screen-print fallback chalta hai — print bilkul rukta nahi.

## Testing

    php -l  (har PHP file)   -> 0 errors
    node --check (POS)       -> OK
    Report render + PDF      -> 4,328 bytes, layout saaf

---

# V77 — Security, roles, audit, closing history, WhatsApp

Aap ke 26-point spec ka pehla aur sab se ahem hissa. Neeche saaf likha
hai kya ho gaya aur kya **nahi** hua.

## Security ka core (#2, #5, #19, #20, #21)

### Cashier isolation — ab SERVER par

Naya `src/Services/Scope.php`. Ab tak har report apne tor par filter
lagati thi, aur cashier ka user id aksar **client se** aata tha — yani
cashier request badal kar doosre ki sales dekh sakta tha. Sirf button
chhupane se yeh theek nahi hota.

Ab usool ek jagah:

    Cashier   -> hamesha sirf apna. User id SESSION se, client se KABHI nahi.
    Manager   -> sab, aur filter laga sakta hai.

`Scope::orderWhere()` / `shiftWhere()` / `ownsShift()` — har list aur
report inhi se guzarti hai.

### Shift ka gate server par (#5)

`pos-finalize` aur `pos-kot` ab pehle `Scope::requireOpenShift()`
chalate hain. Pehle yeh sirf screen par tha — koi seedha API par bill
bana sakta tha aur wo bill kisi shift se juda hi na hota.

Message: *"Your cash counter shift is closed. Please open a new shift
before creating a sale."*

### Item/price sirf manager (#4, #19)

`pos-quick-item`, `menu-item-rate`, `menu-category-create` — teenon par
`Scope::requireManagement()`. Backend par. Button chhupana kaafi nahi.

### Closing snapshot (#21)

Shift band hote hi uske totals **jama kar ke** `snapshot_json` mein
mehfooz ho jate hain. Purani closing report ab dobara nahi ginti —
warna aaj chhapne par alag figure aata (beech mein void/refund ho chuke
hon). Accounts ke liye yeh na-qabil-e-qabool tha.

Purani shifts (jo is se pehle band huin) par report live data se banti
hai; history mein un par saaf "rebuilt" likha aata hai.

## Username-based login (#1)

`users.email` ab **optional**. Username lazmi aur unique. Pehle email
lazmi thi, is liye malik jhoothi email likhta tha
(`cashier1@gmail.com`) — jo na kabhi kaam aayi na sach thi.

Migration purane users ko email se username de deti hai, warna wo login
hi nahi kar pate.

## User Activity Log (#8)

Naya `audit_log` + `Audit.php` + apna page (sirf Owner/Manager).

Abhi in par hooks lage hain: login, logout, sale created, shift closed,
closing reprinted, user created/edited/deleted, password changed, user
suspended, settings changed.

Har record mein: waqt, user, role, action, module, record, purani value,
nayi value, IP. Search aur filter ke saath.

**Yeh record kahin se edit ya delete nahi ho sakta** — jaan-boojh kar.
Jo log badla ja sake wo be-kaar hai.

`Audit::log()` kabhi exception nahi phenkta: audit likhna nakaam ho to
bill ya shift nahi rukna chahiye.

## Shift Closing History (#7)

Naya page. Agar automatic print band ho gaya, printer khali tha ya
kaghaz khatam — purani report dobara chhapti hai.

Cashier sirf **apni** dekhta hai; manager sab. Yeh filter server par
hai. Aur `shiftmgr-report-pdf` par ab ownership check hai: doosre ki
shift ka id daal kar report nikalna band.

## WhatsApp closing report (#9)

Naya `WhatsApp.php` + `whatsapp_queue`.

Do usool:

1. **Closing kabhi na ruke.** Message qatar mein jata hai. WhatsApp
   band ho, key ghalat ho — shift phir bhi band hoti hai aur report
   chhapti hai. Cashier ko counter par rok dena hal nahi.
2. **Ek closing = ek message.** `uq_wa_ref` unique hai, is liye ek shift
   ka message dobara qatar mein nahi ja sakta.

Status: PENDING / SENT / RETRY, attempts, API ka jawab, sent time — sab
DB mein. Manager nakaam message dobara bhej sakta hai.

## Do masle jo MERE hi banaye hue the

**#4 "New Bill" do dafa** — V75 mein maine header par naya button daala
magar strip wala bhi chhora. Ab strip wala chhupa hua hai (id rakha
hai taake F1 aur baqi code na toote).

**#16 sidebar mein software house do dafa** — footer saaf kiya. Ab
user, phir chhote icons ki ek qatar, aur uske neeche **ek saaf "Sign
out" button**. Vendor ki tafseel sirf Support popup mein.

## Logout (#15)

Sidebar mein alag "Sign out" button, aur **POS par bhi** — cashier ko
logout ke liye screens badalni na paren.

Khuli shift **khamoshi se band nahi hoti**. Logout par saaf likha aata
hai: *"Your shift stays open. You can sign back in and continue."*

## Raftaar (#13, #24) — pehla qadam

POS ki asli queries par indexes: barcode, item name, shift, bill no,
order items, payments. Index ke baghair MySQL poori table parhta hai;
50,000 bills ke baad yeh mehsoos hone lagta hai.

## Testing

    php -l  (har PHP file)                -> 0 errors
    duplicate API actions                 -> 0
    php tools/check_pages.php             -> PAGE_CHECK_OK files_with_scripts=46
    node --check (46 pages + public/*.js) -> 0 failures
    SQL schema check: 343 queries         -> 0 problems

---

## JO ABHI NAHI HUA — saaf baat

Yeh spec bohat bara hai. Neeche wale points is package mein **nahi**
hain:

- **#10, #11, #12 Tracked inventory** — items par tracking ka flag,
  closing par opening/sold/remaining ka hisab, WhatsApp mein us ka
  khulasa, aur uski apni report. (`WhatsApp::buildMessage()` mein us ki
  jagah bana di hai, data abhi nahi aata.)
- **#13 POS ki poori optimisation** — indexes lag gaye, magar client
  side ka kaam (caching, kam DB calls) baqi hai.
- **#17, #18 Baqi reports** — Reports ka UI V74 mein bana, magar spec
  ki list mein se kai reports abhi nahi hain: Credit/Due sales, Returns,
  Low Stock, Expiry, Supplier, Due Payments.
- **#8 ke baqi hooks** — abhi 10 actions log hote hain. Price change,
  stock adjust, product create/edit abhi baqi hain.
- **#23 error handling ka poora review** aur **#25 UI consistency ka
  poora pass**.

Yeh sab agli baar. Jo is package mein hai wo poora chalta hai — adhoora
kuch nahi chhora.

---

# V78 — Cashier ka daira, tracked inventory, baqi reports

## 1. Cashier ko ab sirf apna kaam nazar aata hai

**Masla:** `router.php` mein yeh shart thi —

    if($key && $key!=='dashboard' && !Auth::canModule($key)) { 403 }

`$key!=='dashboard'` ka matlab: **dashboard hamesha khula tha**, chahe
user ko ijazat na ho. Cashier wahan se poore branch ki sale dekh sakta
tha.

Ab har page ki apni ijazat check hoti hai. Aur ijazat na ho to khali 403
ke bajaye user ko us ke **apne pehle page** par bhej diya jata hai.

**Cashier ka role ab teen cheezein:**

    shift    — apni shift kholna aur band karna
    pos      — Sale Point (bill, customer, KOT — sab wahin se)
    closing  — apni purani closing reports

`scripts/migrate_role_scope.php` **mojooda** installations par bhi yeh
lagata hai — `seed_roles.php` sirf naye installs par chalta hai, is liye
purane customers ke cashiers ke paas dashboard abhi bhi hota. Migration
role ke saath saath **user par seedhe diye hue extra modules** bhi hata
deti hai, warna role theek hone ke bawajood dashboard dikhta rehta.

Waiter aur Chef ka daira bhi isi tarah tang kiya (tablet/tables, kds).

Malik ne khud koi role banaya ya badla ho to migration us ko **haath
nahi lagati**.

## 2. Tracked inventory (#10, #11, #12)

`inventory_items.is_tracked` naya flag. Inventory page par har item ke
saamne **Track** button.

Malik har item ka hisab nahi chahta — sirf un chand cheezon ka jo qeemti
hain ya jinki chori ka andesha hai. Jo item tracked hai wahi:

- **shift closing report** par (80mm) — opening / sold / remaining
- **WhatsApp** ke khulase mein
- **Reports > Tracked inventory** mein — opening, added, sold, returned,
  adjusted, remaining, CSV ke saath

Opening ka hisab **peeche ki taraf** lagta hai (ab jo para hai, minus jo
aaya, plus jo gaya) — kyunke stock ka "opening" kahin mehfooz nahi hota.

## 3. Paanch nayi reports (#18)

Ab **20 reports**:

- **Credit / unpaid bills** — jo bill poore nahi bhare gaye, customer ka
  phone number ke saath (taake call kar sakein)
- **Returns and refunds** — har void bill, wajah aur cashier ke saath
- **Low stock** — reorder level se neeche
- **Supplier purchases** — kis supplier se kitna khareeda
- **Tracked inventory**

Low stock jaan-boojh kar date range par nahi chalti — stock ki halat
**abhi** ki hoti hai. Report khud yeh baat likh kar batati hai.

## 4. Audit ke baqi hooks (#8)

Ab **14 actions** log hote hain — pehle 10 the. Naye: price change, item
create, inventory item, purchase receive, settings change, item tracking.

## 5. Technical error ab normal user ko nahi (#23)

Shart yeh thi:

    $showReal = debug || superUser || Auth::user();

Yani **har logged-in user** ko — cashier samet — asli SQL/PHP error nazar
aa jata tha. Na usay samajh aata hai, na kaam ka hai, aur database ki
andaruni tafseel bahar chali jati hai.

Ab sirf super admin / manager / debug mode. Baqi sab ko:

    Something went wrong and the action was not completed.
    Nothing was half-saved. Please try again, or tell support
    reference A3F91C22.

Poora stack trace `storage/logs/api-error.log` mein usi reference ke
saath — support seedha wahan dekh leta hai.

## Testing — verification ne do bug pakre

    php -l  (har PHP file)                -> 0 errors
    duplicate API actions                 -> 0
    php tools/check_pages.php             -> PAGE_CHECK_OK files_with_scripts=46
    node --check (46 pages + public/*.js) -> 0 failures
    SQL schema check: 348 queries         -> 0 problems

Pehle pass mein **saat ghalat column** mile aur theek hue:

    inventory_items.item_code       -> asli naam `sku`
    inventory_items.base_unit_id    -> asli naam `stock_unit_id`
    inventory_items.min_stock_level -> asli naam `reorder_level`
    units.unit_code                 -> asli naam `code`

Yeh naye tracked-inventory aur low-stock reports mein the — bina is
check ke dono report chalte hi crash karti.

---

## JO ABHI BHI BAQI HAI

- **#13 POS ki client-side optimisation** — indexes lag chuke, magar
  caching aur kam DB calls baqi.
- **#17 baqi report pages ka UI pass** — Reports section V74 mein bana,
  magar Expiry aur Due Payments jaisi reports abhi nahi hain.
- **#25 poore software ka UI consistency pass**.

---

# V79 — Asli database par testing, aur demo business

Pehli dafa poora software **asli MySQL par chala kar** test hua. Ab tak
sirf lint aur schema-check thi.

## Char asli bug jo sirf chala kar nikle

### 1. Naya business bina roles ke banta tha

`provisionBusiness()` roles banati hi nahi thi. Naya business
`roles = 0` ke saath banta tha — yani malik kisi bhi naye user ko role
de hi nahi sakta tha, aur user creation foreign-key error par gir jati.
`seed_roles.php` sirf haath se chalane par kaam karti thi.

Ab `Platform::ensureRoles()` provisioning ke saath chalti hai. Tasdeeq:
naya business ab 6 roles ke saath banta hai (Cashier ke wahi 3 modules).

### 2. Cashier ko doosre cashier ki shifts dikh rahi thin

`OpsService::shiftList()` mein `Scope` lagaya hi nahi gaya tha —
opening cash, counted cash, variance samet sab nazar aata tha.

**Bilkul wahi ghalti jis se bachne ke liye `Scope` banaya tha.** Do asli
cashiers se test karne par hi pakri gayi.

`runningOrders()` aur `voidLog()` mein bhi yehi tha. Aur **Reports** mein
bhi — cashier Reports khol kar poore branch ki sale dekh sakta tha. Ab
`Scope` `billWhere()` ke andar hai, is liye **har report par khud-ba-khud**
lagta hai; har nayi report mein alag se yaad rakhne ki zaroorat nahi.

### 3. Sync ke columns kabhi bane hi nahi

`migrate_sync_columns.php` mein:

    if (in_array('updated_at', $c)) { $skipped++; continue; }

Jis table par `updated_at` **pehle se** tha (users, user_module_access,
roles...), us par `row_version` aur `origin_node_id` **kabhi nahi bante**
— aur sync inhi do columns se faisla karti hai ke kaunsi row nayi hai.

**Nateeja: permissions ka sync khamoshi se kaam hi nahi karta tha** —
halanke V62.2 ka poora maqsad wahi tha.

Ab teenon columns alag alag check hote hain. Pehle 36 columns bante the,
**ab 91**. `platform_modules` list mein tha hi nahi, wo bhi joda.

### 4. `purchases` report chalte hi crash

    COUNT(gi.id) lines

`lines` MariaDB ka **reserved word** hai — bina backtick ke poori query
syntax error deti hai.

### Aur teen chhoti

- `seed_roles.php` khali database par **fatal crash** deti thi (FK error
  ka stack trace). Ab saaf message aur skip.
- `PosService` mein `guest_count` ki undefined-key warning.
- Shift snapshot **sirf POS ke raste** banta tha. Shift Management page
  se band ki gayi shift ka snapshot banta hi nahi tha — us ki purani
  report har dafa dobara ginti. Ab snapshot ek hi jagah banta hai.

## Test ka natija

    [1]  provisioning                        1/1
    [2]  reference data (units/payments)     3/3
    [3]  no demo data on new business        3/3
    [4]  users: username lazmi, email optional  3/3
    [5]  login by username                   2/2
    [6]  shift gate                          4/4
    [7]  sales + shift linking               3/3
    [8]  second cashier                      2/2
    [9]  ISOLATION                           4/4
    [10] manager sees all                    4/4
    [11] isolation after fix                 5/5
    [12] manager after fix                   3/3
    [13] all 20 reports on real data        20/20
    [15] custom report builder               3/3
    [16] shift close + snapshot + PDF        4/4
    [17] closing history + audit             3/3
    [18-20] demo business                   11/11
    [21] sync plumbing                       9/10*

    * aakhri "fail" test ka apna ghalat function naam tha, code theek hai

**Ahem tasdeeqein:**
- Cashier B ne cashier A ka user id request mein bheja — **nazar-andaz
  hua**, usay phir bhi sirf apna bill mila
- Owner ko teenon bill nazar aaye
- Audit log cashier ke liye **band** hai
- Custom report mein `evil; DROP TABLE users` **reject** hua

## Demo business (naya)

Super admin par naya button: **Create DEMO business**.

Business bharaa hua banta hai: 13 menu items 5 categories mein, 8 tables,
3 customers, 2 suppliers, 6 expense categories, ek printer.

**Har 5 din baad:** jo kuch CUSTOMER ne daala wo mit jata hai, aur jo
SYSTEM ne daala tha wo bacha rehta hai. Demo hamesha dikhane laiq rehta
hai.

Sab se ahem faisla: "system ka data" **ids se** pehchana jata hai
(`demo_seed_rows`), waqt se nahi. Waqt par bharosa karte to demo data
bhi mit jata aur agli dafa customer ko khali software milta.

**Chala kar tasdeeq kiya:**

    seeded:      13 menu items, 8 tables, 3 customers, 1 printer
    customer ne: 1 menu item, 3 bills, 1 shift
    reset ke baad: 13 menu items, 8 tables, 3 customers, 1 printer
                   0 orders, 0 shifts, customer ka item GAYA

Reset boot par aur `scripts/demo_reset.php` se chalta hai.

## Testing

    php -l                     -> 0 errors
    duplicate API actions      -> 0
    check_pages                -> 46 pages
    node --check               -> 0 failures
    END-TO-END on real MySQL   -> 76 pass, 0 real failures

---

# V80 — DEMO business ka button nazar nahi aa raha tha

**Wajah:** button ki class `btn sec` thi — aur `sec` naam ki koi class
`shared.css` mein hai hi nahi. Wahan sirf `primary`, `ghost`, `danger`,
`sm`, `icon` hain.

Button HTML mein maujood tha, magar bina kisi style ke — na rang, na
border, na padding. Baqi buttons ke saath khada tha aur nazar hi nahi
aata tha.

## Aur 12 buttons bhi isi haal mein the

Poore project par check chalaya: **13 buttons** do files mein aisi
classes use kar rahe the jo maujood hi nahi:

    super_admin.html   btn sec  x8    btn mini x3
    index.html         btn sec  x2

Sab theek: `sec` → `ghost`, `mini` → `sm`.

Ab check clean hai — **0 unknown button classes**.

## Demo ka button ab saaf nazar aata hai

Create Business page ke neeche do buttons ek qatar mein:

    [ Create business ]  [ Create DEMO business ]

Aur neeche ek line jo batati hai ke demo kya hai — ready-made menu,
tables aur customers ke saath, aur har 5 din baad customer ka data khud
saaf.

---

# V81 — "class AdminData not found"

**Wajah:** maine `AdminData::audit(...)` likha tha, magar `api.php` mein
`AdminData` **import hi nahi** hai. Baqi poore file mein hamesha poora
raasta likha jata hai: `\Aio\Services\AdminData::audit(...)`.

Ek hi lafz ka farq, aur PHP class dhoond hi nahi paata.

## Teen jagah thi — sirf ek nazar aayi thi

| Kahan | Kab se |
|---|---|
| `sa-demo-create` | V79 |
| `sa-demo-reset` | V79 |
| **`sa-licence-set`** | **V66** |

Teesri wali ahem hai: **licence control bhi isi wajah se toot raha tha.**
Expiry set karne ki koshish par wahi error aata — aur yeh V66 se aisa
hi para tha, kisi ko pata nahi chala kyunke expiry roz set nahi hoti.

Teenon theek. Poore `api.php` par check chalaya — ab koi class bina
import ya poore raste ke istemal nahi ho rahi.

## Asli DB par chala kar tasdeeq

    demo created       13 menu items, 8 tables, 39 seeded rows
    audit written      ok
    reset + audit      ok
    licence audit      ok

Yeh wahi raasta hai jo endpoint chalata hai — is dafa lint par bharosa
nahi kiya, asal mein chala kar dekha.

---

# V82 — Console se business banana

Console mein `delete` to tha, magar **`create` tha hi nahi** — yani
console se business mitaya ja sakta tha aur banaya nahi ja sakta tha.

## Do naye commands

    create "<Name>" --email=owner@x.pk [--owner="Full Name"] [--expiry=YYYY-MM-DD]
    demo   "<Name>" [--email=..] [--expiry=YYYY-MM-DD]

`demo` wahi kaam karta hai magar business ko DEMO nishan lagata hai aur
sample data daal deta hai (13 menu items, 8 tables, customers, suppliers,
printer). Us ka customer data har 5 din baad khud saaf hota hai.

Expiry na di jaye to console **saaf batata hai**:
*"No expiry set — use the Licence control, or pass --expiry=YYYY-MM-DD"*
— warna business hamesha chalta rehta aur kisi ko pata na chalta.

`help` mein dono likhe hue hain.

## Chala kar tasdeeq

    $ create "Console Test Shop" --email=owner@console.pk --expiry=2027-01-31
      Business created: Console Test Shop
        Slug           console-test-shop
        Owner login    owner@console.pk
        Expires        2027-01-31

    $ demo "Console Demo" --expiry=2026-12-31
      DEMO business created: Console Demo
        Sample data: 13 menu items, 8 tables, customers, suppliers, printer
        Customer data clears every 5 days; this sample data stays.

    $ delete console-test-shop
      This command deletes data, so it needs the business name to confirm.

    $ delete console-test-shop --confirm "Wrong Name"
      Confirmation does not match. Expected: Console Test Shop

    $ delete console-test-shop --confirm "Console Test Shop"
      Deleted "Console Test Shop" — 21 rows from 8 tables

---

# V83 — Char adhoore module mukammal

## 1. Inventory edit (pehle rasta hi nahi tha)

Item banane ke **baad** badalne ka koi rasta nahi tha. Naam ghalat ho ya
reorder level, item **delete kar ke dobara banana** parta tha — aur us ke
saath stock history ka rishta toot jata.

Ab har item ke saamne **Edit**: naam, code, barcode, purchase unit,
units-per-purchase, reorder level, tracking, active/inactive.

Item code (SKU) unique rakha jata hai — warna purchasing aur reports
mein do items ek jaise nazar aate hain.

## 2. Profit & Loss ab apni sacchai khud batata hai

Cost of sales recipe consumption se aata hai. Jin items ki recipe nahi,
un ka cost **sifar** rehta hai — yani **profit asal se ZYADA dikhta
hai**. Pehle report yeh baat aam alfaz mein kehti thi.

Ab **ginti ke saath**:

    13 of 13 menu items have NO recipe (100%). Those items are counted
    as sales but cost nothing, so the profit shown here is HIGHER than
    the real profit. Add recipes to your top sellers first.

Aur report ke andar bhi ek line — note aksar nazar-andaz ho jata hai.

Saath mein: `menu_items.food_cost` ab mehfooz hota hai, aur
**Recalculate food cost** se sab dobara ginta hai.

## 3. Purchase Orders (bilkul naya)

Ab tak sirf GRN tha — "maal aa gaya". Supplier ko **order bhejne** ka
koi rasta nahi tha, aur na yeh pata chalta tha ke kya mangwaya hua hai
magar aaya nahi.

Naya page: PO banayein (supplier, expected date, items + qty + cost),
list mein **kitna aaya kitna baqi** (%), aur cancel (wajah ke saath,
magar receive ho chuka PO cancel nahi hota).

Purchase unit item se khud aata hai — warna PO par "10" likha hota aur
kisi ko pata na chalta ke 10 bag hain ya 10 kg.

## 4. Reservation ab TABLE se jurti hai

Booking kisi table se juri hi nahi hoti thi, is liye POS ko pata nahi
chalta tha ke table 8 baje reserved hai.

Ab booking par table chunte hain, aur **ek hi table par do bookings nahi
lag sakteen** — waqt ka overlap check hota hai (default 90 minute).
`reservations-upcoming` se POS agle chand ghanton ki bookings dekh sakta
hai.

## Testing — asli DB par, aur do bug nikle

    [A] inventory edit          2/2
    [B] food cost + coverage    3/3
    [C] purchase orders         5/5
    [D] reservation -> table    4/4
                               14/14

**Do asli bug jo sirf chala kar nikle:**

1. `purchase_order_items` par `tenant_id`/`site_id` hai hi nahi — wo
   parent PO se aate hain. Schema dump mein yeh columns agli table ki
   lines ke saath mil gaye the aur maine ghalat maan liya.
2. `purchase_unit_name` ka koi default nahi — insert wahin ruk jata tha.

**Regression:** isolation + 20 reports = **28/28 pass**.

(Ek dafa 4 fails aaye the — wo container ki date badalne se the, bills
28 Aug ke aur "today" 1 Sep. Sahi date se sab pass.)

## Ab baqi

- **Customer Mobile App** — bana hi nahi (QR ordering asli hai)
- **#13** POS ki client-side raftaar
- **#18** Expiry aur Due Payments reports
- **#25** UI consistency pass

---

# V84 — Offline setup "Undefined variable $argv" par ruk jata tha

**Wajah:** sealed offline build scripts ko is tarah chalati hai —

    php -r "require 'sealed://scripts/demo_reset.php';"

Aur `-r` se chalane par **`$argv` maujood hi nahi hota**. Script us par
`$args` bana rahi thi, PHP warning deti, aur installer native command ka
stderr dekh kar poora setup rok deta.

## Saat scripts mein yehi tha

    demo_reset  self_update  install_schema  node_reset
    reset_super_admin  ensure_v13_login  repair_default_admin

Yani `self_update` aur `node_reset` bhi sealed build mein isi tarah
tootte — auto-update aur node reset dono. Sirf `demo_reset` boot par
chalti thi, is liye wahi nazar aayi.

## Fix — ek jagah

`src/helpers.php` mein naya `cli_args()`:

- `$GLOBALS['argv']` ya `$_SERVER['argv']` se leta hai
- na mile to **khali array** — script apne default par chal padti hai
  (jo boot ke liye bilkul theek hai)

Saat ki saat scripts ab yahi use karti hain. Aage koi nayi script `$argv`
seedha nahi parhegi.

## Testing

    sealed mode (php -r, koi $argv nahi)     7/7 scripts clean
    flags normal tareeqe se                   --tenant= kaam karta hai
    poora boot chain (17 scripts)             0 warnings

Ek cheez khud pakri: mera pehla regex `demo_reset.php` ka `--tenant`
wala block bhi kha gaya tha. Chala kar dekhne par pata chala (flag kaam
karna band ho gaya) aur wapas daal diya.

## V85 — `seed_roles` boot chain mein tha hi nahi

Package ki tasdeeq karte waqt pakra gaya: `seed_roles.php` na
`docker-entrypoint.sh` mein tha, na offline installer mein.

V79 se `provisionBusiness()` khud roles banati hai, is liye **naye**
businesses theek hain. Magar **jo businesses us se pehle bane the** un
ke paas roles ho hi nahi sakte — script sirf haath se chalti thi.

Ab boot par chalti hai (idempotent, aur khali DB par saaf skip).

---

# V86 — Customer khud register kare, khud renew kare

Poora self-service nizam: koi bhi khud account banaye, 14 din trial
chalaye, aur khud payment bhej kar activate karwaye.

## Kaise chalta hai

    1. Customer  register.html  ->  14-day trial, sample data ke saath
    2. Software    3 din pehle  ->  peela banner: "expires in 2 days"
    3. Customer  activate.html  ->  payment ki tafseel + transaction ID
    4. Aap    super admin queue ->  ek click: "Approve & activate"
    5. Software                 ->  chalu, aur data waisa ka waisa

## Do faisle jo maine jaan-boojh kar liye

**1. Transaction ID par KHUD-BA-KHUD activate nahi hota.**

Customer likh sakta hai "12345, paisay bhej diye". Us par software chalu
kar dena **muft mein de dene ke barabar hai**. Is liye darkhwast PENDING
rehti hai aur aap ek click mein manzoor karte hain — **12 ghante ka waada
aap ka hai, system ka nahi.**

Super admin ki screen par confirm karte waqt saaf likha aata hai:
*"Check the payment in your bank/wallet first — this reference is what
the customer typed."*

**2. Trial khatam hone par software band, DATA NAHI.**

Customer ka menu, bills, sab wahin rehta hai; payment ke baad wahin se
chalta hai. Data mitana dabao ka tareeqa hai, hamara nahi. Aur activation
par `is_demo` bhi hat jata hai — warna 5 din baad us ka apna data saaf ho
jata (yeh maine khaas dekha).

## Abuse se rok — public endpoint hai

- **Ek email = ek business.** Warna ek hi bandah bar bar naya trial le kar
  hamesha muft chalata rahega.
- **Ek IP se rozana 3.** Test mein 4 mein se 2 bane, teesri par ruk gaya.
- Naam, phone, email, password sab par validation.

## Aap ke liye

Super admin mein naya **Activations** page, waiting ki ginti badge ke
saath. Har darkhwast par: business, reference, method, amount, abhi ki
expiry, customer ka phone (**Call** button ke saath). Approve karte waqt
months chun sakte hain, aur **mojooda expiry se aage** barhta hai —
customer ke bache hue din zaya nahi hote.

**Payment details** button se apna bank / Easypaisa / JazzCash aur qeemat
set karein — customer ko activation screen par yehi dikhta hai. Khali
chhorein to usay sirf phone number milega aur wo call karega.

## Testing — asli DB par, 20/20

    [1] register + trial + sample data + login    5/5
    [2] abuse rok (email, input, IP throttle)     3/3
    [3] expiry warning aur expired                2/2
    [4] payment ki ittila                         4/4
    [5] manzoori aur activation                   6/6

**Khaas tasdeeq:** transaction ID bhejne ke baad bhi licence **expired
hi raha** — jab tak manzoori na mili. Yehi is design ka poora nuqta hai.

Ek bug pakra: `mb_strlen` bina fallback ke — mbstring har PHP build par
nahi hoti. Chala kar nikla, lint se nahi.

---

# V87 — FBR: connection test ab sach batata hai

## Pehle apni ghalti durust

Maine kaha tha ke QR mein fallback matn ja raha hai. **Wo ghalat tha.**
Jo number QR mein aaya — `82055826090113502220*Test*` — wo FBR ki apni
service se hi aaya hai. Chain kaam kar rahi hai.

## Asli masla service ki setting mein hai

**1. `*Test*` ka lahiq** — FiscalizationService (IMS.exe) khud lagati hai
jab wo **test/sandbox mode** mein ho. Us haalat mein invoices FBR ko
waqai report NAHI hotin. Yeh us service ki apni configuration hai.

**2. Number POS ID se shuru nahi hota** — `82055826...` mein `158823`
kahin nahi. Iska matlab: service apna **khud ka registered POS** use kar
rahi hai, hamara bheja hua POSID nahi.

Dono cheezein service ki config hain, hamare code ki nahi. Magar hamara
software yeh baat **saaf nahi bata raha tha** — yahi meri kami thi.

## Mera bug: connection test adhoora payload bhejta tha

Pehle test sirf `POSID`, `USIN` aur khali `Items` bhejta tha. Is liye
service hamesha yeh deti:

    Code 402 — Model validation failed
    Required property 'DateTime' not found

Yani test se kuch pata hi nahi chalta tha, chahe sab theek ho.

Ab test **poora, valid model** bhejta hai (DateTime, totals, ek item
sameet) aur jawab parh kar saaf batata hai:

- Invoice number mila ya nahi
- Number mein `Test` hai to: *"service is in TEST mode, invoices are NOT
  being reported to FBR for real"*
- Number POS ID se shuru nahi hota to: *"the service is using its own
  registered POS — not the one entered here"*

Aur Settings par ab yeh poora jawab ek box mein dikhta hai; pehle ek
line mein kat jata tha aur asli wajah nazar hi nahi aati thi.

## Testing

    php -l   -> 0 errors
    node --check (settings) -> OK

**NahI chala:** asli FiscalizationService par (mere paas nahi hai).

---

# V88 — Shift close par khud-ba-khud backup, aur restore

Backup pehle se tha (console `backup <slug>`), magar **restore bilkul
nahi tha** — yani backup lena be-maani tha.

## Ab kya hota hai

Har dafa shift band hoti hai, poore business ka backup ban kar
**`D:\Backup\`** mein chala jata hai, tareekh ke saath:

    Royal-Grill_2026-09-01_14-12_SH-260901-141225.json

Online aur offline dono par. Naya page: **Backup & Restore**.

## Teen faisle

**1. Backup KABHI shift close nahi rokega.** Disk bhari ho, D: drive na
ho, folder par ijazat na ho — shift phir bhi band hogi. Cashier ko
counter par rok dena hal nahi hai. Magar nakami **khamoshi se nahi
jati**: audit log mein jati hai aur Backup page par laal mein nazar
aati hai.

**2. D: har PC par nahi hota.** Pehli koshish `D:\Backup`, na mile to
software ka apna folder. Aur customer khud rasta badal sakta hai.
Cloud par `D:\` ka sawaal hi nahi — wahan backup server par banta hai
aur download ho sakta hai.

**3. Restore mojooda data par LIKH DETA HAI.** Is liye do rukawatein:
- Pehle **khud-ba-khud safety backup** — ghalat file se restore ho jaye
  to wapasi ka rasta khula rahe
- Business ka naam **hu-ba-hu** likhna parta hai

Restore sirf Owner/Manager, aur sirf apne business ka data (`tenant_id`
se scoped).

## Do ehtiyat jo jaan-boojh kar rakhe

- `read()` sirf **file ka naam** leta hai, poora rasta nahi — warna koi
  `../../config/local.php` maang kar server ki file parh leta. Test mein
  yeh koshish rok di gayi.
- 60 din se purane backups khud hat jate hain, warna disk bhar jati hai
  aur kisi ko pata nahi chalta.

## Testing — asli DB par, 15/15

    [1] folder (D: / fallback)          2/2
    [2] backup + log + list             4/4
    [3] data mita kar RESTORE           4/4
    [4] kharab file aur path escape     2/2
    [5] shift close par khud-ba-khud    2/2

**Asli tasdeeq:** 13 menu items delete kiye, phir backup se restore —
**13 wapas aa gaye**, aur purana data safety backup mein mehfooz raha.

**Ek cheez jo maine khud pakri:** POS ka apna shift-close raasta
`OpsService` se alag hai. Sirf ek jagah hook lagata to counter se band
ki gayi shift ka backup banta hi nahi — aur rozana wahi raasta chalta
hai. Dono jagah hook hai.

---

# V89 — Login recovery, dropdown hataya, naya icon

## 1. Cloud par password badalne se local par kuch nahi hota tha

**Bug:** password update `updated_at` badalta tha magar **`row_version`
nahi**. Sync usi ginti se faisla karti hai ke row nayi hai ya nahi. Is
liye cloud par badla hua password branch computer tak pohanchta hi nahi
tha.

Char jagah yehi kami thi: user ka password change, super admin ka
business-admin reset, self-signup ka password, aur user suspend/activate.
Chaaron theek.

## 2. Password bhool jane par local recovery

Sync theek hone se bhi kaafi nahi tha: **branch par internet ho hi na**,
to cloud se koi madad nahi milti.

Naya `RESET_PASSWORD.bat` — usi computer par, internet ke baghair:

    SIGN IN WITH         NAME              ROLE       STATUS
    owner                Faheem Ahmed      Owner      ACTIVE  (owner)
    counter1             Ali Raza          Cashier    ACTIVE

    Sign-in name: counter1
    New password: Naya@123

`row_version` yahan bhi barhta hai — warna agli sync par cloud ka purana
password wapas aa kar isay mita deta.

Hifazat par ek baat: yeh sirf us ke haath mein hai jis ke paas computer
ka access hai. Jis ke paas computer hai, us ke paas database bhi hai —
yahan koi aur taala lagana sirf malik ko takleef deta.

## 3. Login screen se user ka dropdown hata diya

Offline par login screen **sab users ki fehrist** dikhati thi, naam aur
role ke saath. Do wajah se ghalat tha: har aane wale ko pata chal jata
tha ke kaun kaam karta hai aur kis ka kya darja hai (aadha password khud
hi de dena hai), aur aap ne kaha yeh nahi chahiye.

Ab saada khana: **Username** aur **Password**. Aur "Demo login —
admin@urbanspoon.local / Admin@123" wali line bhi hata di — asli
customer ki login screen par kisi aur ka password likha hona theek nahi.

## 4. Icon

Aap ka `pos.ico` **102x89** tha — Windows ko murabba chahiye, warna
shortcut par khinch kar bigra hua dikhta hai. Aur us mein sirf ek size
thi.

Ab wahi design, magar:

- **Saat sizes** — 16, 24, 32, 48, 64, 128, 256
- Rounded square, wahi neela (`#00A2FF`)
- Font se dobara likha gaya, is liye har size par kinare saaf
- **16 aur 24px par sirf "POS"** — "smart" itna chhota ho kar parha hi
  nahi jata, aur taskbar par yehi size chalti hai

`RESET_PASSWORD.bat` offline package mein bhi jata hai.

---

# V90 — Update ab poochta hai, aur offline renewal key

## 1. Update khud download nahi hota

Pehle launcher `self_update.php --download` chalata tha — naya build
**khud** aa jata tha. Aap ne kaha yeh customer ki marzi honi chahiye,
aur baat theek hai: kisi ka internet mehnga hai, kisi ka slow, aur
bina poochhe utaar lena theek nahi.

Ab teen halatein:

| Launcher par | Matlab |
|---|---|
| kuch nahi | aap ke paas latest build hai |
| **A NEW UPDATE IS AVAILABLE: V91** | naya build hai, **utara nahi gaya**. `GET_UPDATE.bat` |
| **DOWNLOADED AND READY** | utar chuka, `INSTALL_UPDATE.bat` |

`GET_UPDATE.bat` pehle **poochta hai**: *"Yeh internet se download hoga
(taqreeban 2-5 MB). Download karein? (Y/N)"*. `N` par: *"Theek hai. Aap
ka software waise hi chalta rahega."*

Yani teen alag faisle, teenon customer ke: check (khud), download
(poocha jata hai), install (poocha jata hai).

## 2. Offline renewal key

**Masla:** branch par internet na ho aur licence khatam ho jaye. Sync se
licence barh hi nahi sakta, portal khulta nahi. Customer ne paisay bhej
diye aur software band para hai.

**Hal:** aap super admin se ek key banate hain, phone ya WhatsApp par
customer ko dete hain, wo software mein daal deta hai — **internet ki
koi zaroorat nahi**.

    Licence control -> "Make activation key" -> 30/90/180/365 days

    P9X0-07G0-A3CY-CS67-Z868-BWJC

Customer ke liye: **Activate / Renew** page par "Have an activation key?"

### Key kaise mehfooz hai

- **Har business ka apna raaz** (`tenants.licence_secret`) — ek business
  ki key doosre par **nahi** chalti (test mein rok di gayi)
- **Ek key, ek dafa** (`licence_keys_used`) — dobara daalne par inkaar
- **Key ki apni miyaad 30 din** — purani parhi hui key baad mein bekaar
- Factory reset se bhi `licence_keys_used` nahi mitti, warna wahi key
  dobara chal jati

### Likhne ki ghalatiyan maaf

Customer phone par sun kar likhta hai, aur `O`/`0`, `I`/`1` mein farq
nazar nahi aata. Key ka alphabet inhen shamil hi nahi karta, aur jo
likha jaye wo khud sudhar jata hai. Chhote harf, spaces, dashes — sab
chalte hain.

Computer ki tareekh peeche ho to saaf batata hai: *"This computer's date
looks wrong. Fix the date and try again."* — warna key "mustaqbil ki"
lagti aur customer ko wajah samajh na aati.

### Ek baat saaf

Raaz customer ke apne computer par hoti hai. Jo bandah waqai chahe aur
jaanta ho, apni key bana sakta hai. Yeh nizam **suhoolat** ke liye hai,
chori rokne ka taala nahi. Asli hifazat yeh hai ke har business ka raaz
alag hai — ek jagah ka masla baqi customers tak nahi jata.

## Testing — 12/12

    [1] key banao                     2/2
    [2] expired licence + key lagao   3/3
    [3] jo rukna chahiye              3/3   (dobara, jaali, adhoori)
    [4] doosre business ki key        1/1   REJECTED
    [5] likhne ki ghalatiyan          1/1
    [6] bache hue din zaya nahi hote  1/1   (2026-10-16 -> 2026-10-26)
    [7] server par record             1/1

Regression: backup 15/15.

## Ek cheez jo package karte waqt pakri

`migrate_selfservice` aur `seed_roles` **offline installer ki list mein
aaye hi nahi the** — mere pichle do patch chup-chaap fail ho gaye the
(anchor match nahi hua aur maine natija check nahi kiya). Ab dono
maujood hain, aur maine har migration ko dono jagah (Docker + installer)
gin kar tasdeeq kiya.

---

# V91 — RESET_PASSWORD.bat chal hi nahi raha tha

    Windows cannot find 'runtime\mariadb\bin\mysqld.exe'

**Meri ghalti:** maine `mysqld.exe` ka rasta **andaze se** likh diya tha.
MariaDB apne version wale folder mein khulta hai —
`runtime\mariadb\mariadb-11.4.2-winx64\bin\` — is liye seedha rasta kabhi
sahi nahi hota.

Software khud yeh kaam theek karta hai: `resolve_mariadb.ps1` exe ko
**dhoond kar** (`-Recurse`) chalati hai. Ab `RESET_PASSWORD.bat` bhi
wahi script use karti hai, apna rasta nahi banati.

## Ek aur masla jo test karte waqt nikla

Database band ho to script **latak** jati thi — PHP connect ka intezar
karta rehta, na error, na kuch. Customer ke liye yeh sab se bura tajurba
hai.

Ab 5 second mein saaf jawab:

    Local database se rabta nahi ho saka.
    START_RESTAURANT.bat chala kar software kholein, phir yeh
    file dobara chalayein.

## Chala kar tasdeeq

    DB band  ->  saaf paighaam, 5 second mein (pehle latakta tha)
    DB chalu ->  users ki fehrist:

      SIGN IN WITH    NAME                    ROLE      STATUS
      demo            Demo Restaurant Owner   -         ACTIVE (owner)
      democash        Demo Cashier            Cashier   ACTIVE

    password reset  ->  LOGIN OK

---

# V92 — "Database files could not be extracted" — bina wajah ke

    Extracting........ done.
    Database files could not be extracted.

Yeh do lines ek saath aana hi bata deta hai ke kuch ghalat hai: " done."
chhap gaya, phir nakami — **aur koi wajah nahi**.

## Wajah

    Receive-Job $job -ErrorAction SilentlyContinue | Out-Null

`Expand-Archive` ki **asli ghalti phenk di jati thi**. Job khatam hota,
hum " done." likh dete, phir `mysqld.exe` na milne par khali "could not
be extracted" keh dete. Customer ke paas koi rasta nahi bachta tha.

## Teen fix

**1. Asli ghalti ab dikhti hai.** Job ka natija parha jata hai aur wajah
saaf likhi jati hai: `Reason: ...`

**2. Doosra tareeqa.** Expand-Archive nakaam ho to .NET ka
`ZipFile::ExtractToDirectory` aazmaya jata hai. Purane Windows
PowerShell 5.1 par Expand-Archive bare zip par nakaam ho jata hai;
.NET wala wahan bhi chal jata hai. Yehi ehtiyat **PHP runtime** ke liye
bhi lagayi — wahan bilkul yehi masla ho sakta tha.

**3. Download ki tasdeeq.** "Database : 100% complete" ka matlab yeh
**nahi** ke file waqai ZIP hai. Server HTML error page bhej de, ya
connection beech mein toote, to file ban jati hai magar khulti nahi. Ab
size (20MB+) aur ZIP ka nishan (`PK`) dono check hote hain, aur saaf
paighaam milta hai:

    The downloaded database file is not usable (incomplete or blocked).
    This usually means the internet dropped, or a firewall replaced
    the download.

## Aur nakami par madad

Ab agar phir bhi mysqld na mile to:

- Jo files nikleen un ke naam dikhata hai (ya batata hai ke kuch nahi nikla)
- Path 250 se lamba ho to: *"Move this package somewhere shorter, for
  example C:\\SmartPOS"* — Windows 260 characters se aage nahi jata, aur
  `Downloads` ka lamba naam is masle ki aam wajah hai

## Testing

    PowerShell balance (4 scripts)  -> OK
    php -l                          -> 0 errors

**NahI chala:** asli Windows par (mere paas nahi hai). Magar ab nakami
par **wajah nazar aayegi**, jo pehle nahi aati thi — aur wahi asal
tabdeeli hai.

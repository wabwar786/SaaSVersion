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

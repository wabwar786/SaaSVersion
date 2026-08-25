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

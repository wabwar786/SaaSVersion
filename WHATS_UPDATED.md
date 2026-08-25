# Urban Spoon — UI Rebuild & Completion (V14)

Poora project naye unified design system par rebuild kiya gaya hai aur har dead/placeholder
page ko functional bana diya gaya hai. Backend, PHP, scripts, config, batch files aur data
stores waise ke waise hain — sirf UI aur pages update/complete hue hain.

## Kya naya hai

**Naya design system** — `approved_ui/shared.css` (+ `public/shared.css`)
- Ek hi refined Urban Spoon red brand (pehle 2 clashing themes thी: green index vs red dashboard)
- Clean SaaS operations-console look: grouped sidebar + icons, KPI cards, crisp tables, badges,
  modals, toasts, empty states, mobile drawer
- Legacy compatibility layer taake purane rich pages (POS/KDS/inventory/purchasing/recipe/users)
  na tooten aur naya theme inherit karein

**Ek unified sidebar** — `approved_ui/shell.js`
- Har page par same grouped navigation (pehle har file mein duplicate inline sidebar tha)
- User ke assigned modules ke hisab se auto-filter, active-state, mobile drawer

**Config-driven page engine** — `approved_ui/module.js` + `module_config.js`
- 23 pehle "broken" (sirf static table, dead buttons) pages ab poori tarah functional:
  KPIs + search + add/edit/delete (localStorage), realistic Pakistani restaurant demo data
- Pages: suppliers, customers, staff, riders, printers, branches, tables, menu, reservations,
  expenses, promotions, loyalty, whatsapp, delivery, orders, online, wastage, transfer,
  count, void, accounting, fbr, shift

**Bespoke rebuilt pages**
- `index.html` / `dashboard.html` — live command-center dashboard (sales, hourly chart, alerts)
- `reports.html` — analytics (hourly bars, channel-mix donut, top items, payments, Today/Week/Month)
- `settings.html` — business/tax/receipt/operations settings (saves locally)
- `offline_sync.html` — offline & cloud-sync status
- `customer_mobile_app.html` — branded customer ordering app preview
- `customer_web_qr.html` — scan-to-order QR flow
- `login/signup/setup/signup_pending` — reskinned to new red brand (functionality same)

**Preserved as-is (already functional, ab naya theme)**
- `restaurant_pos.html`, `kds.html`, `restaurant_order_taker_tablet.html` — fullscreen operational screens
- `inventory_creation.html`, `purchasing.html`, `recipe_making.html`, `users_access.html`
  — apni functionality intact, naya sidebar + theme

## Chalane ka tareeqa
Pehle jaise — `START_RESTAURANT.bat` (ya `start_local_unix.sh`). Login:
**admin@urbanspoon.local** / **Admin@123**

> Note: sab shared assets `public/` mein bhi copy kar diye gaye hain taake PHP router theek serve kare.

# Left Menu — Module Status

Build V69 · 27 Aug 2026

Har page khol kar dekha gaya: data kahan se aata hai, kahan jata hai.
Yeh raye nahi, code se nikali hui haqeeqat hai.

---

## Legend

| | Matlab |
|---|---|
| **WORKING** | Asli database par. Data save hota hai, sync hota hai, reports mein aata hai. |
| **PARTIAL** | Kaam karta hai magar kuch hissa adhoora ya doosri jagah se juda nahi. |
| **SHELL** | Sirf khoobsurat screen. Data `ui_records` mein para rehta hai — POS, reports, stock kisi se juda nahi. |
| **DEMO** | Hardcoded naqli data. Customer ka apna data dikhata hi nahi. |

---

## WORKING — 18 modules

| Module | Kahan likhta hai |
|---|---|
| **Dashboard** | asli KPIs, sync status |
| **Sale Point / POS** | `orders`, `order_items`, `payments`, `kitchen_tickets` — 1,870 lines |
| **Order Taker Tablet** | `pos-boot` / `pos-kot` — asli KOT (V68 se LAN par) |
| **Menu & Categories** | `menu_items`, `menu_categories` |
| **Tables & Floors** | `dining_tables`, `floors` (V62 mein joda gaya) |
| **Customers** | `customers` |
| **Suppliers** | `suppliers` |
| **Expenses** | `expenses` |
| **Wastage / Adjustment** | `stock_adjustments` + asli stock movement |
| **Printers / Devices** | `printers` + routing (V66) |
| **Users & Access** | `users`, `user_roles`, `user_module_access` |
| **Settings** | `tenants`, `sites`, `site_settings`, `pos_settings` (V63) |
| **Reports** | 15 reports, sab SQL se (V67) |
| **Offline / Sync** | asli sync engine |
| **Kitchen / KDS** | `kitchen_tickets` — POS ke asli KOT (V70) |
| **Opening & Closing Shift** | `cashier_shifts` + asli cash reconciliation (V70) |
| **Running Orders** | `orders` — POS ke khule bills (V70) |
| **Void / Refund** | asli bill VOID + stock wapas (V70) |

## PARTIAL — 4 modules

| Module | Kya chalta hai | Kya kami hai |
|---|---|---|
| **Inventory** | items/categories DB mein (`inventory-item-create`) | edit nahi, low-stock alert sirf naam ka |
| **Purchasing** | GRN asli stock barhata hai (`purchase-receive`) | purchase order (PO) ka hissa nahi, supplier payment alag nahi |
| **Recipe & Food Cost** | recipe DB mein (`recipe-save`) | food-cost ka hisab screen par, report mein nahi |
| **Customer Web / QR** | QR order asli `qr_orders` banata hai | menu/branding customer ke apne data se nahi |

## SHELL — 11 modules

Yeh sab `ui_records` mein likhte hain. Screen bharti hai, data bhi bacha
rehta hai — **magar kisi asli cheez se juda nahi**. Misal: "Running
Orders" mein order banayein to POS ko pata nahi chalta; "Stock Transfer"
karein to stock nahi hilta.

- Online Orders
- Stock Transfer
- Physical Stock Count
- Delivery
- Rider Management
- Reservations
- Loyalty / Membership
- WhatsApp / Notifications
- Accounting / Cash
- Discounts / Promotions
- Staff / Roles
- Multi-Branch

## DEMO — 2 modules

Inme **hardcoded naqli data** hai. Customer ka apna data dikhata hi nahi.

| Module | Masla |
|---|---|
| **Customer Mobile App** | demo screens |
| **Customer Web / QR** | menu demo se |

---

## FBR — left menu se hata diya gaya

FBR ka apna page nahi hona chahiye tha: wo **Sale Point ke saath juda
hua** kaam hai. Settings hai bhi Settings mein (V64 se), aur invoice
POS par bill close hote waqt banti hai. Ab menu se hata diya —
`fbr.html` file rehne di gayi hai taake purane bookmark na tootein.

---

## Mashwara — kaam ki tarteeb

**~~1. KDS~~ — V70 mein ho gaya.**
**~~2. Shift / Running Orders / Void~~ — V70 mein ho gaya.**

**1. Stock Transfer / Physical Count.** Inventory ka bharosa inhi par
hai — abhi stock haath se theek karna parta hai.

**2. Baqi (Loyalty, Reservations, Delivery, Riders, WhatsApp,
Multi-Branch).** Yeh alag alag kaam hain. Har ek se pehle poochna
chahiye ke customer waqai use karega ya nahi — warna 14 adhoore
modules banate rahenge.

**Ek raye:** jo modules abhi shell hain, unhen super admin ke
**Features** se band kar dein. Customer ko wo menu mein nazar hi na
aayen. Adhoora feature dikhna, na dikhne se bura hai.

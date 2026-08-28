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

## WORKING — 30 modules

Sab asli tables par. Data save hota hai, sync hota hai, reports mein aata hai.

**Sale & service:** Dashboard · Sale Point / POS · Order Taker Tablet ·
Kitchen / KDS · Tables & Floors · Running Orders · Void / Refund ·
Opening & Closing Shift · Online Orders · Reservations

**Menu & stock:** Menu & Categories · Wastage / Adjustment ·
Stock Transfer · Physical Stock Count

**People:** Users & Access · Staff · Customers · Loyalty · Riders

**Money:** Expenses · Accounting / Cash · Discounts / Promotions · Reports

**System:** Printers / Devices · Settings · Multi-Branch ·
WhatsApp / Notifications · Offline / Sync · Suppliers

## PARTIAL — 4 modules

| Module | Kya chalta hai | Kya kami hai |
|---|---|---|
| **Inventory** | items/categories DB mein | edit nahi, low-stock alert sirf naam ka |
| **Purchasing** | GRN asli stock barhata hai | purchase order (PO) ka hissa nahi |
| **Recipe & Food Cost** | recipe DB mein | food-cost sirf screen par, report mein nahi |
| **Delivery** | delivery orders + rider asli hain | rider auto-assign nahi, live tracking nahi |

## DEMO — 2 modules

| Module | Masla |
|---|---|
| **Customer Mobile App** | demo screens — asli app nahi bana |
| **Customer Web / QR** | QR order asli hai, magar menu/branding demo se |

---

## FBR — left menu se hata diya gaya

FBR ka apna page nahi hona chahiye tha: wo **Sale Point ke saath juda
hua** kaam hai. Settings Settings mein hain (V64 se) aur invoice POS par
bill close hote waqt banti hai.

---

## Mashwara — kaam ki tarteeb

**1. Inventory ka edit.** Item banane ke baad badalne ka rasta nahi —
sirf delete kar ke dobara banana parta hai.

**2. Purchase Orders.** Abhi sirf GRN (maal aaya) hai. Supplier ko
order bhejne ka hissa nahi.

**3. Recipe cost report mein.** Food cost screen par nazar aata hai
magar Profit & Loss usay recipe consumption se leta hai — jin items ki
recipe nahi, un ka cost sifar rehta hai.

**4. Customer Mobile App.** Yeh asli mobile app ka kaam hai, web page
ka nahi. Isse pehle tay karna chahiye ke waqai chahiye ya QR ordering
kaafi hai.

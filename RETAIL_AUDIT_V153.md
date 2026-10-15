# RETAIL (SUPERMARKET) VERSION — AUDIT
### V153 build 2026-10-13 · Wabwar Software House

Yeh andaza nahi hai. Har module ke liye teen cheezein alag alag naap ki gayin,
asli database aur asli browser par:

1. **Server par data kahan girta hai** — asli table, ya `ui_records` ka JSON blob
2. **Screen par wapas aata hai ya nahi** — DB mein row daal kar screen gini gayi
3. **Kuch hota bhi hai ya nahi** — stock barha? ledger bana? report mein aaya?

Tamam 42 retail screens HTTP 200 par khulti hain aur kisi par JavaScript error
nahi hai. **Masla toote hue safhon ka nahi — adhoori wiring ka hai.**

---

## 1 · EK NAZAR MEIN

| Darja | Modules | Ginti |
|---|---|---|
| **MUKAMMAL** — asli table, asli hisaab, screen par data | POS, Products, Departments, Categories, Brands, UOM, Batches, Counters, Sales/Invoices, Customers, Khata, Stock on Hand, Pricing, Scale Items, Loyalty (view), Reports, Dashboard, Settings, Users, Activity, Offline/Sync, Shift | **22** |
| **ADHOORA** — server par asli, magar screen par list khali | Suppliers, Expenses, Staff, Printers, Branches, Promotions | **6** |
| **GHALAT JAGAH JURA** — bridge hai magar retail ka nahi | Wastage | **1** |
| **NAAM KA MODULE** — sirf JSON blob, kuch nahi hota | GRN, Purchase Orders, Purchasing, Purchase Return, Stock Transfer, Stock Count, Returns/Void, Closing History, WhatsApp | **9** |
| **JAAN BOOJH KAR OFFLINE-ONLY** | Barcode & Shelf Labels, Tax / FBR Digital Invoice | **2** |

Yani: **22 mukammal, 16 adhoore ya khokhle, 2 by design offline.**

---

## 2 · TEEN DARJE — MASLE KI ASAL JARH

Retail ka data teen alag rastay se guzarta hai, aur **teeno ek jaise nazar aate
hain** — screen, form, "Saved" ka toast, sab same. Farq sirf yeh hai ke peeche
kya hai:

```
   records-save / records-list
            |
            +--  RetailCatalog   ->  rtl_*  asli tables      (7 modules)
            |                        validation, stock, index
            |
            +--  ModuleBridge    ->  asli shared tables      (9 modules)
            |                        validation, delete checks
            |
            +--  (koi nahi)      ->  ui_records  JSON blob   (9 modules)
                                     koi validation nahi, koi asar nahi
```

Yeh teesra rasta hi asal masla hai. `ui_records` ek `module_key + data_json`
ka dabba hai. Row mehfooz ho jati hai, is liye test mein sab theek lagta hai —
magar us row ka kisi stock, kisi ledger, kisi report se koi taalluq nahi.

**Saboot:** GRN post kiya (20 pcs receive) — server ne `ok:true` diya,
stock 37 ka 37 raha. Return post kiya — wahi. Dono dafa koi error nahi,
koi warning nahi.

```
before: {'Audit Sugar 1kg': 37}
GRN qty 20 posted      -> ok:true
after:  {'Audit Sugar 1kg': 37}     <- kuch nahi hua
```

Isi tarah `ok:true` par aap ko yaqeen ho jata hai ke module chal raha hai.

---

## 3 · TEEN BUGS JO IS AUDIT MEIN PAKRE GAYE

### BUG 1 — Product ka barcode kabhi save hi nahi hota (SAB SE AHEM)

Product Catalog screen se naya product banayein, barcode bharein, Save —
"Product added" aa jata hai. **Phir POS par wo barcode kabhi scan nahi hota.**

Wajah ek lafz ka farq hai:

```js
// module_config.js, products config
onCreate: function (d) { d.barcodes = d.barcode ? [d.barcode] : []; delete d.barcode; }
                                ^^^^^^^^                              ^^^^^^^^^^^^^^^^
// server (RetailCatalog::save) parhta hai:
$bc = trim((string)($d['barcode'] ?? ''));     // singular
```

UI `barcode` ko **delete** kar ke `barcodes[]` bhejta hai; server sirf
`barcode` dekhta hai aur `barcodes[]` ko nazar-andaz kar deta hai.
`rtl_product_barcodes` mein kabhi row nahi banti.

**Saboot — dono tarah se product banaya:**

| Kaise banaya | retail-lookup ka natija |
|---|---|
| `barcodes: ["8901234567890"]` (jaise UI bhejta hai) | `Not found: 8901234567890` |
| `barcode: "8900000000017"` (jaise server chahta hai) | mil gaya |

Yeh sirf ek ghalat field naam hai, magar iska matlab yeh hai ke **screen se
banaya hua koi bhi product counter par scan nahi hota.** Supermarket ke liye
is se bara masla koi nahi.

---

### BUG 2 — Chhe module ki list screen par hamesha khali

Server par data mojood hai, screen sifar dikhati hai. Customer product add
karta hai, "Saved" dekhta hai, reload karta hai — row ghayab.

Wajah `retail_api.js` mein hai:

```js
var MODULE_OF = { products:'products', departments:'departments',
  categories:'categories', brands:'brands', units:'uom',
  batches:'batches', counters:'counters' };
...
var mod = MODULE_OF[key];
if (!mod) return [];          // <- suppliers, expenses, staff... sab yahan
```

`S.get('suppliers')` is fehrist mein hai hi nahi, is liye **khali array**
wapas karta hai — chahe server par kitni bhi rows hon. Likhna (`records-save`)
alag raaste se jata hai, is liye **save chalta hai aur list nahi** — yani
data chup chaap jama hota rehta hai jo kisi ko nazar nahi aata.

**Saboot — ek ek row DB mein daal kar screen gini:**

| Module | Server rows | Screen rows |
|---|---|---|
| Customers | 1 | 1 |
| Products | 2 | 2 |
| Sales | 1 | 1 |
| Stock | 2 | 2 |
| Khata | 1 | 1 |
| **Suppliers** | 1 | **0** |
| **Expenses** | 1 | **0** |
| **Staff** | 1 | **0** |
| **Branches** | 1 | **0** |
| **GRN** | 2 | **0** |

Printers aur Promotions bhi isi fehrist mein hain (unki validation ne test row
reject kar di — jo khud is baat ka saboot hai ke server side asli hai).

Yeh bug aur Bug 1 dono ek hi wajah se paida hue: **client ki fehrist aur server
ki fehrist do alag jagah likhi hui hain.** Wahi sabaq jo tax settings (V148),
module groups (V141) aur offline tools (V145) par mila tha.

---

### BUG 3 — Wastage retail ka hai hi nahi

`wastage` ModuleBridge par juri hui hai (asli table), magar wo bridge
**restaurant ke `inventory_items`** mein item dhoondhta hai — retail ke
`rtl_products` mein nahi:

```php
"SELECT id,name,... FROM inventory_items WHERE id=? AND site_id=? ..."
```

Supermarket ke product kabhi `inventory_items` mein hote hi nahi, is liye:

```
wastage save -> {"ok":false,"message":"Inventory item not found: "}
```

Supermarket apne kisi product ka write-off kar hi nahi sakta. Upar se retail
ka wastage form `item` ek **text box** hai (dropdown nahi), to cashier kuch bhi
likh sakta hai aur wo kabhi match nahi karega.

---

## 4 · MODULE DAR MODULE

### MUKAMMAL (22)

| Module | Kya tasdeeq hua |
|---|---|
| **Retail POS counter** | Bill save hua (INV-1001), bill number mila, **stock 40 → 37 khud ghata**, sales list mein aaya, hold/release aur reprint ke endpoints mojood |
| **Product Catalog** | `rtl_products` asli table, validation, SKU khud banta — **magar Bug 1 dekhein** |
| **Departments / Categories / Brands** | `rtl_*` asli tables, sort order, screen par data |
| **Units & Pack Sizes (UOM)** | 9 units, pack conversion samet |
| **Batch & Expiry** | `rtl_batches`, FIFO aur expiry ki tarteeb |
| **Counter Management** | `rtl_counters`, 2 counters screen par |
| **Sales / Invoices** | asli bills, KPI, filter |
| **Customers** | ModuleBridge + `retail-customers` — list screen par aati hai |
| **Khata (Credit)** | customers par bani, `rpos-ledger` endpoint chalta hai |
| **Stock on Hand / Pricing / Scale Items** | teeno `products` par bane hain, is liye asli data — margin aur PLU samet |
| **Loyalty** | customers par view |
| **Reports** | 18 asli reports, sab bills ke asli data par: daily summary, by item/dept/brand/hour, payment mix, price level, invoice detail, cashier, counter, basket, reprints, tax, FBR reconcile. `report-run` chala kar dekha |
| **Dashboard** | asli aankre |
| **Settings / Users & Access / Activity Log / Offline-Sync / Shift** | COMMON modules, restaurant ke saath mushtarak aur aazmaye hue |

### ADHOORA — server asli, screen khali (6)

Suppliers · Expenses · Staff · Printers · Branches · Promotions

Inka server side **poora bana hua hai** — asli tables, asli validation
(`"Enter the printer IP address"`, `"Percent discount must be between 0 and
100"`), delete par dependency checks. Sirf client ki ek fehrist mein inka naam
nahi. **Yeh sab se sasta kaam hai: `MODULE_OF` mein chhe line.**

### GHALAT JAGAH JURA (1)

Wastage — Bug 3.

### NAAM KA MODULE (9)

| Module | Hona kya chahiye tha | Abhi kya hota hai |
|---|---|---|
| **GRN (Goods Receipt)** | maal aaye, **stock barhe**, cost update ho, supplier ka payable barhe | JSON row, stock waisa ka waisa |
| **Purchase Orders** | PO bane, GRN us se mile, pending track ho | JSON row |
| **Purchasing** | kharidari ka khata | JSON row |
| **Purchase Return** | maal wapas, **stock ghate**, supplier debit note | JSON row |
| **Stock Transfer** | ek branch se doosri — **dono taraf stock badle** | JSON row |
| **Physical Stock Count** | ginti ke baad **adjustment post ho** | JSON row |
| **Returns / Void** | bill wapas, **stock wapas**, refund ka record | JSON row |
| **Closing History** | band shifts ka record | JSON row — asli shift data `shiftmgr-list` mein hai, screen use parhti hi nahi |
| **WhatsApp templates** | templates DB mein | JSON row |

In nau mein se **saat seedhe stock ko chhute hain**. Jab tak yeh adhoore hain,
supermarket ka stock **sirf ghat sakta hai** (POS se), barh nahi sakta — siwaye
is ke ke koi product edit kar ke `stock_qty` haath se likhe.

### OFFLINE-ONLY (2) — yeh bug nahi

- **Barcode & Shelf Labels** — label printer (TSC/Zebra) counter PC par lagta hai
- **Tax / FBR Digital Invoice** — FBR ki fiscal service local node par chalti hai

Dono screens cloud par saaf saaf yeh likh kar batati hain. Yeh faisla theek hai.

---

## 5 · KAAM KI TARTEEB — MERI RAI

| # | Kaam | Mehnat | Asar |
|---|---|---|---|
| 1 | **Bug 1 — barcode ka field naam** | ~10 minute | Is ke baghair catalog se banaya product POS par bekaar hai. Sab se zyada asar, sab se kam mehnat. |
| 2 | **Bug 2 — `MODULE_OF` mein chhe module** | ~30 minute | Chhe module jo *pehle se bane hue hain* achanak chalne lag jayenge |
| 3 | **GRN ko asli banao** (stock + cost + payable) | 1 din | Is ke baghair supermarket ka stock kabhi barh hi nahi sakta |
| 4 | **Returns / Void ko asli banao** (stock wapas) | ~half din | Counter par roz ka kaam hai |
| 5 | **Bug 3 — Wastage ko `rtl_products` par le jao** | ~half din | Expiry supermarket ka roz ka masla hai |
| 6 | Stock Count → asli adjustment | ~half din | |
| 7 | Purchase Return / Stock Transfer / PO / Purchasing | 2–3 din | Ek hi purchase engine se chaaron ban jayenge |
| 8 | Closing History ko `shiftmgr-list` se joro | ~2 ghante | Data pehle se mojood hai, screen parhti nahi |

**1 aur 2 mila kar aadhe ghante ka kaam hai aur saat module seedha kar dete
hain.** Yeh pehle hone chahiyen.

---

## 6 · EK PEHRA JO BANNA CHAHIYE

Yeh teeno bug ek hi shakal ke hain: **do jagah likhi hui ek fehrist, jo
khamoshi se alag ho gayi.** `MODULE_OF` (client) ke muqabil `RetailCatalog` +
`ModuleBridge` (server); `barcode` (server) ke muqabil `barcodes` (client).

Isi project mein yeh teesri dafa hua hai — V141 (module groups), V145 (offline
tools), V148 (tax settings). Har dafa hal ek hi tha: **ek fehrist, ek jagah,
aur ek checker jo deploy par milan kare.**

Tajweez: `scripts/check_retail_wiring.php` — jo har `MODULE_CONFIGS` ki key
uthaye aur dekhe ke (a) server us module ko handle karta hai, aur (b) client ki
`MODULE_OF` mein uska storeKey mojood hai. Deploy log mein `RETAIL_WIRING_OK`,
ya wo modules jin ki list chup chaap khali rahegi. Bilkul `COLLATION_OK` aur
`SETTINGS_CHECK_OK` ki tarah.

Is pehre ke baghair agla module phir isi tarah adhoora chala jayega, aur pata
tab chalega jab kisi customer ka supplier ghayab hoga.

---

## 7 · AUDIT KAISE KIYA GAYA

- Taza MariaDB, poora schema (93 tables) + 32 migrations
- Asli RETAIL tenant (Al Madina Super Store) banaya, usi se login
- 42 retail screens asli browser (Chromium) mein kholin — status, rendered rows,
  KPI, aur JavaScript errors record kiye
- Har module par `records-list` chalaya aur `bridged` marker parha
  (`retail` / `true` / koi nahi) — isi se teen darje saaf hue
- Har module mein ek row DB mein daali, phir screen ki rows gini — isi se
  Bug 2 pakra gaya
- POS ka poora chakkar chalaya: product → barcode lookup → sale → stock →
  sales list → report
- GRN / return / wastage post kar ke stock se pehle aur baad ka farq dekha

---

*Wabwar Software House · www.wabwar.pk · 0342-5095104*

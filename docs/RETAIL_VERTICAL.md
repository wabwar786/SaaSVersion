# RETAIL (Supermarket) Vertical — Setup & Data Contract

Yeh document batata hai ke supermarket vertical kaise chalta hai, kya
badla gaya, aur kaunsa UI field kaunse DB column par jata hai.

---

## 1. Chalane ka tareeqa

### Cloud (Railway) par

```bash
php scripts/migrate_retail.php          # rtl_* tables + region_profile
php scripts/seed_industry_modules.php   # module catalog COMMON/RESTAURANT/RETAIL
```

Dono **idempotent** hain — dobara chalane par kuch nahi tootta.

### Offline (branch PC) par

Kuch alag nahi karna. `START_RESTAURANT.bat` dono scripts khud chala
deta hai (`tools/windows_bootstrap.ps1` ki master-data list mein shamil
hain), is liye purane offline nodes bhi agle start par khud supermarket
ke qabil ho jate hain.

### Business banana

Super Admin → **Create business**:

| Field | Naya |
|---|---|
| Industry | Restaurant **/ Supermarket / Retail** |
| Region | **Pakistan / United Kingdom / United States** |

Region se apne aap set hota hai: currency (PKR/GBP/USD), timezone, tax
mode (inclusive/exclusive), tax driver (FBR/VAT/Sales Tax), barcode
standard (EAN-13/UPC-A), weight unit (kg/lb) aur credit ka naam
(Khata / Account Customer).

Business bante hi milta hai: 8 departments, standard units (pack
conversion ke saath), 2 counters, aur 6 retail roles — Owner/Admin,
Store Manager, Cashier, Floor Staff, Storekeeper, Purchase Officer.

---

## 2. Industry gating — kaise kaam karta hai

`platform_modules.industry_code` teen buckets mein hai:

| Bucket | Count | Misal |
|---|---|---|
| `COMMON` | 26 | settings, users, reports, suppliers, expenses, sync |
| `RESTAURANT` | 13 | pos, kds, tables, recipe, menu, riders |
| `RETAIL` | 14 | rpos, products, khata, grn, batches, labels |

Har module query is filter se guzarti hai:

```sql
WHERE is_active=1 AND industry_code IN (:tenant_industry, 'COMMON')
```

`Auth::canModule()` mein industry gate **admin par bhi** lagta hai —
warna supermarket ka owner URL mein `/kds.html` likh kar restaurant ki
screen khol leta, kyunke `isAdmin()` neeche har cheez ko haan keh deta
hai.

**Ahem:** retail POS ki module key `rpos` hai, `pos` nahi. Dono verticals
ko ek hi key par rakhna permissions ko uljha deta.

---

## 3. Data contract — UI field → DB column

UI ke field keys **wahi** hain jo DB columns hain. Isi liye screens ko
rewrite nahi karna para jab demo data se asli MySQL par shift kiya.

### `rtl_products`
`sku · name · department_id · category_id · brand_id · base_unit_id ·
tax_rate · cost_price · retail_price · wholesale_price · mrp ·
stock_qty · min_stock · max_stock · is_scale_item · plu_code ·
track_batch · shelf_life_days · status`

Barcodes alag table mein (`rtl_product_barcodes`) — ek product ke kai
barcode hote hain (purana stock, imported pack). UI `barcodes[]` array
dekhta hai; save par `barcode` field bhejta hai.

### `rtl_product_uom` — pack sizes
`product_id · unit_id · barcode · factor · cost_price · retail_price ·
is_default_purchase`

**Usool:** stock hamesha **base unit** mein girta hai. Carton kharida,
piece becha — `factor` dono ko milata hai.

### `rtl_sales` / `rtl_sale_items`
Bill header aur lines. `reprint_count` + `last_reprint_at` header par;
har copy ka apna record `rtl_bill_reprints` mein.

### `rtl_customer_ledger`
Har credit bill (`SALE`) aur har recovery (`RECEIPT`). Sirf
`customers.balance` barhana kaafi nahi — recovery ke waqt customer
poochta hai "kis bill ka?"

### Sync
Har `rtl_*` table mein `tenant_id`, `site_id`, `updated_at`,
`deleted_at`, `row_version`, `origin_node_id`. Sync watermark-based hai
(`updated_at`), is liye `config/local.php` ki list mein register karna
kaafi hai:

- **push** (branch → cloud): sab kuch, bills samet
- **pull** (cloud → branch): sirf master data (products, barcodes, uom,
  departments, categories, brands, units). **Bills pull mein
  jaan-boojh kar nahi** — woh branch par bante hain aur sirf upar jate hain.

---

## 4. Hifazat ke faisle

**Paise ka hisaab sirf server par.** Client sirf `product_id` + `qty`
bhejta hai. Rate DB se aata hai aur total `RegionProfile::billTotals()`
se dobara banta hai. Test: client ne 2450 wale item ka rate 5 bheja —
server ne bill reject kar diya ("short by 2,445").

**Ek bill = ek transaction.** Sale, lines, stock decrement, batch FIFO
aur khata entry sab ek `DB::tx()` mein. Bijli jaye to ya poora bill
banta hai ya kuch bhi nahi — aisa kabhi nahi ke stock kat jaye aur bill
na bane.

**Credit limit bill banne se pehle** check hoti hai, `FOR UPDATE` lock
ke saath — do counters ek hi khata ek waqt mein limit se upar nahi le ja
sakte.

**Duplicate bill ginti mein aata hai.** Reprint cash chori ka aam raasta
hai. Har copy `rtl_bill_reprints` mein aur `audit_log` mein jati hai:
kis ne, kab, kaunse counter se, kya wajah. Copy number receipt par bhi
chhapta hai.

**Stock zero se neeche ja sakta hai.** Counter par bill rok dena is se
bara masla hai; ghalti reports mein nazar aa jayegi.

---

## 5. Nayi / badli hui files

**Nayi**

```
scripts/migrate_retail.php          rtl_* schema, region_profile, UOM conversion
scripts/seed_industry_modules.php   industry-aware module catalog
src/Services/RegionProfile.php      PK/UK/US — tax math, scale barcode
src/Services/RetailCatalog.php      products, barcodes, UOM, lookup, defaults
src/Services/RetailPos.php          bill, FIFO batch, khata, held, reprint
approved_ui/retail/                 supermarket ki saari screens
```

**Badli hui**

```
src/Auth.php                tenantIndustry()/tenantRegion(), module filter, industry gate
src/Services/Platform.php   region_profile, retail roles, retail defaults, industry key fix
public/api.php              14 retail endpoints + needRetail() gate
public/router.php           retail UI directory, module map, /retail/* assets
approved_ui/super_admin.html  Industry: Supermarket, + Region selector
config/local.php            rtl_* sync registration
tools/windows_bootstrap.ps1 offline par retail migrations
```

---

## 6. Test ke nataij

Container mein PHP 8.3 + MariaDB par chala kar:

- Dono industries ke business bane; UK region → GBP + Europe/London
- RETAIL tenant: 40 modules, `rpos` haan, `kds`/`recipe` nahi
- RESTAURANT tenant: 39 modules (pehle jitne), `rpos` nahi
- Scan: barcode, SKU, pack barcode (×24), scale label (1.25 kg)
- Bill: stock ghata, FIFO ne purana batch pehle khatam kiya
- Price tampering reject
- Duplicate bill: copy 1, copy 2, dono audit mein
- Restaurant ki koi table nahi badli

**Do bugs jo test ne pakre aur theek hue:**

1. `units` par unique index sirf `code` par tha — **doosra** supermarket
   ban hi nahi sakta tha ("Duplicate entry 'DOZ'"). Ab index
   `(code, tenant_id)` par hai.
2. Super Admin ka form `industry` bhejta tha, API `industry_code` parhti
   thi — dropdown ka chunav khamoshi se zaya ho jata tha aur har business
   RESTAURANT ban jata. Ab dono naam chalte hain.

---

## 7. Abhi baaki

Yeh screens abhi sirf design hain, API par nahi:
GRN, Purchase Return, Labels/barcode printing, Khata ledger screen,
Price Management, Scale items, Shift & Z-Report, Reports pack,
Stock transfer/count/wastage (retail version).

Inke module keys aur permissions pehle se seed hain, is liye screen
banate hi chal parenge.

---

## 8. Tenant isolation — kya test hua

Requirement: **kisi business ka 1% data bhi doosre business ko nazar
nahi aana chahiye.** Yeh farz nahi kiya gaya, chala kar dekha gaya.

### Attack test — Store B ne Store A ki asli IDs le kar hamla kiya

| Koshish | Nateeja |
|---|---|
| A ka product id se parhna | blocked |
| A ka bill id se parhna | blocked |
| A ka bill duplicate print karna | blocked |
| A ke customer ka khata ledger | blocked |
| A ke customer ka khata chhoona | blocked |
| A ka product bech kar uska stock ghatana | blocked |
| A ke product ko overwrite karna | blocked |
| A ka batch badalna | blocked |
| A ki unit badalna | blocked |
| A ka product delete karna | blocked |

### Teen asli bugs jo in tests ne pakre

**1. Session switch leak (sanjeeda).** Cache sirf
`$_SESSION['tenant_industry']` mein thi. Ek hi browser mein pehle
restaurant ka login page kholein, phir usi session mein supermarket ke
user se login karein — cache purani reh jati thi. Supermarket wale user
ko restaurant ke **39 modules** milte the aur `canModule('kds')` TRUE
aata tha, yani doosre business ki screen khul jati.
→ Ab cache tenant id ke saath bandhi hai (`$_SESSION['tenant_ctx']`),
aur login/logout par saaf hoti hai.

**2. Global unit cross-tenant edit.** `saveUnit()` ke update par tenant
check nahi tha. Ek supermarket global KG ka `conversion_factor` 999 kar
sakta tha — aur woh **har business** par lagta, restaurant samet.
→ Ab: apni unit edit hoti hai, global unit nahi (saaf message ke saath),
kisi aur ki milti hi nahi.

**3. Doosra supermarket ban hi nahi sakta tha.** `units` par unique
index sirf `code` par tha, is liye doosre tenant ke default units pehle
tenant se takra kar poora business creation gira dete the.
→ Index ab `(code, tenant_id)` par.

### Defence in depth

Har child query par bhi `tenant_id` laga diya gaya — chahe uska parent
pehle se tenant-scoped ho. Ek layer par bharosa nahi kiya jata.
Audit ka nateeja: **0 unscoped queries** in `RetailCatalog` aur
`RetailPos`.

Iske ilawa: agar koi id kisi aur tenant ki nikle to us row par likhne
ke bajaye nayi id banti hai — purani row kabhi overwrite nahi hoti.

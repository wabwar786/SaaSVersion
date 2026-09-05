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

---

## 9. Demo business — har type ka ek

Super Admin → **Demo business** button → modal khulta hai jismein
business types ki list hai. Jis type ka demo chahiye us par
**Create demo**. Jo pehle se maujood hai us par **Open** (client link).

| Type | Demo mein kya milta hai |
|---|---|
| Restaurant | Menu (13 items), 5 categories, 8 tables, customers, suppliers |
| Supermarket / Retail | 14 products (barcode + 3 scale items), 10 brands, carton barcode (×24), 3 batches (ek near-expiry), khata wale customers, suppliers |

**Har type ka sirf EK demo.** Doosri koshish par server saaf inkar karta
hai aur maujooda demo ka naam batata hai. Do restaurant demos ka koi
faida nahi, aur teesra banate rehna console ko kachra bana deta hai.
Naya chahiye to purana Businesses list se delete karein.

**Reset ka usool retail par bhi wahi:** har 5 din baad customer ka daala
hua data (`rtl_sales`, `rtl_sale_items`, `rtl_held_bills`,
`rtl_customer_ledger`, bill reprints) saaf ho jata hai — magar sample
catalog (`rtl_products`, brands, departments, batches) bacha rehta hai,
taake demo hamesha dikhane laiq rahe. Test: reset ke baad bhi 14 products
salamat rahe.

---

## 10. "Unknown column region_profile" ka hal

Yeh error is liye aata tha ke **deploy par retail migration chalti hi
nahi thi** — `tools/docker-entrypoint.sh` ki migration list mein woh
shamil nahi thi.

Do jagah theek kiya gaya:

1. **Entrypoint** — `migrate_retail.php` aur `seed_industry_modules.php`
   ab FAST section mein hain (Apache start se pehle). Agla deploy khud
   column bana dega.

2. **Platform.php** — ab column ki maujoodgi dekh kar likhta hai. Agar
   migration kisi wajah se na chali ho to business phir bhi ban jata hai
   (region default PK), poori console nahi girti. Yeh isliye zaroori tha
   ke **restaurant** business creation bhi isi error se ruk gayi thi —
   ek pending migration ne wo cheez bhi maar di jiska retail se koi
   taalluq nahi tha.

Test: column jaan-boojh kar `DROP` kar ke dono industries ke business
banaye gaye — dono bane.

---

## 11. "Business created" card ka purana bug

Card mein **Business: [object Object]**, Client link khali, aur Password
hamesha "(set by owner)" dikhta tha.

Wajah: `sa-business-create` jawab is shakl mein deta hai —

```json
{ "ok": true, "business": { "client_link": "...", "admin_password": "...", ... } }
```

— magar UI `r.client_link` seedha **top level** se parh rahi thi, jo hai
hi nahi. `r.business` ek object tha, is liye `[object Object]` chhap gaya.

**Yeh sirf badsurti nahi thi.** `admin_password` server sirf **ek dafa**
bhejta hai — DB mein sirf bcrypt hash hai, dobara nikala nahi ja sakta.
Yani har naye business ka password bante hi zaya ho jata tha, aur owner
ko login dene ke liye `reset_super_admin.php` jaisa koi rasta dhoondna
parta tha.

Fix: card ab `r.business || r` parhta hai (dono shaklein qubool, kyunke
`sa-demo-create` flat bhejta hai). Sath hi industry aur region bhi card
par dikhte hain, aur "Copy credentials" ab link + email + password teeno
copy karta hai.

---

## 12. Saari screens ab maujood

Pehle sidebar 39 links dikhata tha magar sirf 11 pages bane hue the —
baaki 28 par **Page not found** aata tha. Ab 41 pages hain, sab 200
dete hain (HTTP test se tasdeeq).

| Tarah | Screens |
|---|---|
| Pehle se bani | Dashboard, POS, Products, Departments, Categories, Brands, UOM, Batches, Suppliers, Customers, Counters, Promotions, Sales |
| Nayi — catalog/stock | Stock on Hand, Price Management, Scale Items, **Barcode & Shelf Labels** |
| Nayi — purchasing | Purchasing, Purchase Orders, GRN, Purchase Return, Stock Transfer, Stock Count, Write-off |
| Nayi — sale | Shift, Closing History, Returns/Void, Khata |
| Nayi — finance | Expenses, Accounting, **Tax / Digital Invoice**, Reports |
| Nayi — system | Staff, Users, Printers, Branches, **Offline & Sync**, Activity, Settings |

**Kis level par:** Labels, Reports, Accounting, Shift, Settings, Tax aur
Offline & Sync asli data par chalti hain. Purchasing, PO, GRN, Purchase
Return, Transfer, Count aur Returns abhi generic record store par hain
(wahi jagah jahan restaurant ke kai modules aaj bhi hain) — data mehfooz
rehta hai aur sync hota hai, magar line-by-line GRN posting aur PO se
stock auto-receive agli batch mein real `rtl_` tables par jayenge.

---

## 13. Offline version download

**Offline & Sync** screen par download button hai. Yeh `offline-package`
endpoint chalata hai jo:

- ZIP banata hai jismein **poora app sealed** hota hai (code raw nahi milta)
- Us tenant ka **sync token seal ke andar** rakhta hai — plaintext disk par nahi
- Retail UI ki saari 48 files bundle mein jati hain
- `migrate_retail.php` + `seed_industry_modules.php` bhi saath jate hain,
  aur `windows_bootstrap.ps1` unhein har start par chalata hai — is liye
  purane offline nodes bhi khud supermarket ke qabil ho jate hain

Test: retail tenant se download kiya — 1.2 MB ZIP,
`SmartPOS_demosupermarket_<date>.zip`.

Download **sirf online portal se** hota hai (package cloud par banta hai)
aur **sirf Admin/Manager** kar sakta hai. Offline node par button khud
disable ho jata hai.

---

## 14. Sirf offline chalne wale modules (retail)

Restaurant ki tarah retail mein bhi kuch cheezein counter ke hardware se
bandhi hain. Yeh **Offline & Sync** screen par table ki shakl mein saaf
likhi hain, aur `tax.html` cloud par khud ko block kar leta hai (404
dene ke bajaye wajah batata hai):

| Module | Kahan | Wajah |
|---|---|---|
| Tax / FBR / PRA / KPRA | Sirf offline | Fiscal service usi PC par hota hai (localhost:8524); cloud us tak pohanch hi nahi sakta |
| Receipt printer + cash drawer | Sirf offline | USB/serial se counter PC se juda; drawer printer ke kick command se |
| Label printing | Sirf offline | TSPL/ZPL label printer local device hai (cloud par browser print milta hai) |
| Weighing scale (live read) | Sirf offline | Serial/USB. **Scale ka chhapa hua label** cloud par bhi scan ho jata hai |
| POS, stock, khata, GRN, reports | Dono | Sync hoti rehti hai |
| Offline download | Sirf online | Package cloud par banta hai, sync token us mein seal hota hai |

---

## 15. Offline node par "Page not found" — kya hua tha

Offline package mein **har** page not-found de raha tha, `login.html`
samet.

**Wajah (meri hi ghalti):** offline package `build_offline_bundle.php`
banata hai, jo code mein **literal string** dhoondh kar badalta hai:

```
dirname(__DIR__).'/approved_ui/     ->     'sealed://approved_ui/
```

Maine router mein retail ke liye path ko variable bana diya tha:

```php
$file = dirname(__DIR__).'/'.$uiDir.'/'.$name;   // ← rewrite match nahi karta
```

Cloud par yeh bilkul theek chalta hai (files disk par hain), magar
sealed package mein files **disk par hoti hi nahi** — seal ke andar
hoti hain. Rewrite na lagne se router asli filesystem par file dhoondta
raha aur har page 404 ho gaya.

**Fix:** dono raaste alag alag **literal** likhe gaye taake rewrite dono
par lage:

```php
$file = ($uiDir === 'approved_ui/retail')
      ? dirname(__DIR__).'/approved_ui/retail/'.$name
      : dirname(__DIR__).'/approved_ui/'.$name;
```

**Sabaq:** cloud par test kar lena kaafi nahi tha. Sealed package ka
apna path model hai, aur wahi customer ko milta hai.

**Test:** asli offline package bana kar, alag DB (port 3307) par chala
kar — 41 ki 41 pages **200**. `kds.html` 404 (retail tenant), download
button khud disabled ("Aap pehle se offline version par hain"), aur
`APP_ROLE=local` hone ki wajah se FBR ka gate hat gaya yani tax module
node par chalta hai.

---

## 16. Auto-sync start nahi hoti thi (purana bug)

```
This command cannot be run because "RedirectStandardOutput" and
"RedirectStandardError" are same.
```

`tools/start_offline.ps1` do jagah stdout aur stderr **ek hi file**
(`sync.log`) par bhej raha tha. PowerShell yeh qubool nahi karta, is
liye auto-sync **kabhi start hi nahi hoti thi** — har offline node par,
restaurant walon par bhi. User ko har baar "Sync now" haath se dabana
parta tha.

Fix: `sync.log` (stdout) aur `sync.err.log` (stderr) alag. Dono jagah
theek kiya — pehla start aur watchdog ka restart bhi.

---

## 17. Storage aur speed — asli aankray

Sab kuch **50,000 products + 20,000 bills (160,000 lines)** ke asli load
test se, sealed offline package par chala kar.

### Storage

Maapi hui row size: `rtl_sales` 390 B, `rtl_sale_items` 278 B.
Ek 8-item bill ≈ **2.6 KB**.

| Bills / din | Roz | Mahina | Saal | 5 saal |
|---|---|---|---|---|
| 500 | 1.2 MB | 37 MB | 0.45 GB | 2.2 GB |
| 1,500 | 3.7 MB | 112 MB | 1.3 GB | 6.7 GB |
| 3,000 | 7.5 MB | 225 MB | 2.7 GB | 13.4 GB |
| 5,000 | 12.5 MB | 375 MB | 4.5 GB | 22.3 GB |

Catalog alag se: 50,000 products ≈ 43 MB + barcodes ≈ 20 MB = **63 MB**.

**Maximum:** InnoDB ki apni had 64 TB per table hai — amli tor par
**jitni disk hai utni**. 500 GB ke aam counter PC par 3,000 bills rozana
wala store **100 saal** se zyada chala sakta hai. Storage is software
ki hadd nahi hai.

### Rush-hour speed — do bug jo load test ne pakre

Chhote data par sab theek lagta tha. 50,000 products par:

| Kaam | Pehle | Ab | Farq |
|---|---|---|---|
| Naam se search | **906 ms** | 3.2 ms | 280× |
| Bill save (8 items) | **131 ms** | 4.4 ms | 30× |
| Barcode scan | 0.36 ms | 0.30 ms | — |

**Search 906 ms kyun thi:** `LIKE '%q%'` + barcodes ka LEFT JOIN — 24,524
rows ka scan aur temporary table, har harf par. Cashier naam type karta
to POS har keystroke par ek second ruk jata.
→ Ab teen qadam: barcode/SKU ka theek match (index hit), phir naam ka
**prefix** (index range), aur tab hi FULLTEXT. Zyadatar scans pehle qadam
par khatam.

**Bill save 131 ms kyun tha:** "agla bill number" nikalne wali query
`ORDER BY created_at` par **filesort** kar rahi thi — 9,955 rows har
bill par. Sath hi har line ke liye barcodes ki faltu query.
→ Index `ix_rs_seq (tenant_id, site_id, created_at)` aur POS ke liye
`productLite()` (barcodes fetch nahi karta).

### Offline vs online

HTTP round-trip, sealed package, 50k catalog:

| | Offline node | Online (cloud) |
|---|---|---|
| Barcode scan | **~19 ms** | 19 ms + internet ka round trip |
| Naam search | **~21 ms** | 21 ms + internet ka round trip |
| Bill save | ~20 ms | 20 ms + internet ka round trip |

Server ka kaam dono jagah barabar hai — farq **sirf internet** ka hai.
Pakistan se Railway (US/EU) tak har request ka round trip aam tor par
**200–400 ms** hota hai. Har scan par yeh lagega.

**Nateeja:** counter ke liye **offline version** hi sahi hai. 1,000
items ka bill offline mein ~19 ms per scan hai; online mein wahi scan
250 ms+ ho jata hai aur rush mein line lag jati hai. Cloud head office,
reports, multi-branch aur catalog push ke liye behtareen hai — counter
ke liye nahi.

Ek chhoti madad: `retail_api.js` har code ka natija cache karta hai, is
liye ek hi barcode dobara scan ho (jo counter par aam hai) to doosri
dafa 0 ms lagta hai — online par bhi.

---

## 18. Super Admin par "Page not found" — asli wajah

Production par `super_admin.html` 404 de raha tha.

**Wajah:** router sirf itna dekhta tha ke tenant RETAIL hai ya nahi, aur
phir **har** page `approved_ui/retail/` se uthata tha. Jis browser mein
ek dafa supermarket ka user login kar leta, us mein yeh sab 404 ho jate:

```
super_admin.html   login.html    setup.html
signup.html        qr.html       pair.html      register.html
activate.html      backup_restore.html
```

Yeh pages kisi vertical ke hain hi nahi — platform ke hain — magar
router unhein bhi retail folder mein dhoondta tha, jahan woh maujood
nahi.

**"Baar baar" isi liye:** ek dafa retail demo mein login karne ke baad,
usi browser mein Super Admin console kholna nakaam ho jata tha. Naya
browser ya incognito mein khul jata — isi liye masla kabhi aata kabhi
nahi.

**Fix — ab teen darje:**

1. **Public pages** (login, signup, setup, super admin, qr, pair,
   register) — hamesha `approved_ui/` se
2. **Shared utility pages** (activate, backup_restore) — inka bhi koi
   vertical nahi; hamesha `approved_ui/` se
3. **Baaki sab** — tenant ke industry ke hisaab se

Permission check bhi theek kiya: shared pages ka module key restaurant
wale map mein hai, retail map mein dhoondne par null milta aur check
khamoshi se skip ho jata.

**Test (teeno halaton mein):**

| Halat | Nateeja |
|---|---|
| Logged out — 8 public pages | 8/8 · 200 |
| RETAIL logged in — 8 public + 2 shared | 10/10 · 200 |
| RETAIL logged in — 12 retail pages | 12/12 · 200 |
| RETAIL logged in — `kds.html` | 404 (durust) |

**Sabaq:** pehle maine sirf "logged out" aur "retail pages" test kiye
the. Jo halat tooti thi — *retail user logged in, phir platform ka page
kholna* — wo test hi nahi hui thi.

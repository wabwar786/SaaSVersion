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

---

## 19. Restaurant POS — chaar masle, chaaron ki asli wajah

### a) "Complete Payment" par bill chhapta hi nahi tha

`printNode()` `window.open()` istemal karti thi. Browser popup sirf tab
kholne deta hai jab woh **user ke click se seedha** nikle. Yahan print
`pos-finalize` ka jawab aane ke baad, do `requestAnimationFrame` ke
andar se chalti thi — browser ke liye "user ne nahi khola", is liye
popup blocker chup-chaap rok deta tha. Cashier ko sirf toast dikhta
tha, kagaz kuch nahi.

→ Ab popup ke bajaye **chhupa hua iframe**. Blocker ka sawal hi nahi.

### b) QR chhapta nahi tha

`w.print()` image load hone se **pehle** chal jati thi. Kagaz par QR ki
jagah khali reh jati thi.

→ Ab print se pehle saari images ka intezar, **2 second ki hadd** ke
sath — koi image printer ko rok nahi sakti.

### c) Offline version bahut susth (online theek)

Do wajuhat, dono maapi gayin:

**1. FBR ka intezar — har bill par.** `pos-finalize` bill band karne ke
baad `FiscalService::submit()` ko *inline* bulata hai. Agar fiscal
service us PC par chal na rahi ho (aam baat), to `file_get_contents`
poore **8 second** rukta tha — har bill par. Aur `php -S` ek waqt mein
ek hi request leta hai, is liye un 8 second mein poori screen jami
rehti thi.

Online par yeh nazar hi nahi aata: cloud par provider `NONE` hota hai
(FBR sirf offline chalta hai), to wahan koi intezar hai hi nahi. Isi
liye "offline slow, online theek" lagta tha.

Maapa hua (atki hui service ke sath):

| | Waqt |
|---|---|
| Purana (timeout 8s) | **8.0 s** per bill |
| Naya timeout (3s) | 3.0 s |
| Agle 5 bill (circuit breaker) | **0.000 s** |

Rush mein 6 bill: pehle **48 second** ka intezar, ab 3 second ek dafa.
Breaker 60 second ka hai; service wapas aate hi (ya Settings ke "Test
connection" se) khud khul jata hai. Bill kabhi rukta nahi — PENDING
mark ho kar chhapta hai aur queue retry karti hai.

**2. Har bill ke baad cloud sync.** `register_shutdown_function('sync_nudge')`
jawab bhejne ke baad chalta hai, magar single-threaded server tab tak
**agli request nahi uthata**. Yani har bill ke baad cashier ki agli
click sync ke khatam hone ka intezar karti thi.

→ Ab 20 second mein ek dafa se zyada nahi. Bill phir bhi foran upar
jata hai; background loop apna kaam karta rehta hai.

### d) POS responsive nahi tha

Sirf ek breakpoint tha (1150px). Us se neeche layout wahi do-column
rehta tha — chhote counter monitor aur tablet par cart ki column dab
kar bekaar ho jati thi.

→ Ab char darje: 1150 / 1000 / **820 (ek column — upar items, neeche
sticky cart)** / 560 (mobile). Sath hi touch screens ke liye bare tap
targets (`pointer:coarse`).

---

## 20. Offline POS ki raftaari — asal sabab (maapa hua)

Print aur FBR theek karne ke baad bhi offline susth tha. Profiling se
do aur cheezein nikleen — dono har request par lagti thin, har CSS aur
image par bhi, kyunke sab router se guzarti hain.

### a) Sealed package har request par decrypt hota tha

`runtime/boot.php` har request par yeh sab karta tha:

| Qadam | Waqt |
|---|---|
| blob parhna (705 KB) + HMAC | 3.2 ms |
| AES-256-GCM decrypt | 0.5 ms |
| gzinflate | **11.5 ms** |
| unserialize (224 files) | 0.3 ms |
| **kul** | **15.5 ms** |

Aur yeh ek tez Linux machine par. Counter ke aam Windows PC par kahin
zyada.

→ Ab pehli dafa decrypt kar ke files `runtime/.cache` mein nikal di
jati hain. Uske baad har request seedha wahi files parhti hai.

**Maapa hua:** boot **15.5 ms → 0.32 ms**.

Package badalte hi stamp badal jata hai, purani cache poori tarah saaf
hoti hai aur nayi ban jati hai — yani update ke baad purana code kabhi
nahi chalta.

### b) OPcache tha hi nahi

`sealed://` stream se `require` kiye gaye code ko OPcache **cache kar
hi nahi sakta**. Yani har request par saara PHP dobara compile hota
tha.

→ Ab files asli disk par hain, aur `php.ini` mein OPcache on hai
(`opcache.enable_cli=1` lazmi hai — built-in server CLI SAPI par
chalta hai).

**Maapa hua:** app ka PHP load **15.3 ms → 2.1 ms**.

### Kul nateeja

| | Pehle | Ab |
|---|---|---|
| Har request ka boot | 15.5 ms | 0.32 ms |
| PHP compile | 15.3 ms | 2.1 ms |
| Login page (asli request) | ~16–19 ms | **~2 ms** |
| FBR band ho to har bill | **8 s** | 3 s ek dafa, phir 0 |

Cloud par bhi OPcache Dockerfile mein add kar diya gaya — wahan bhi
har request par compile ho raha tha.

---

## 21. Receipt ke fonts

Thermal printer par chhota font kagaz par aur bhi chhota lagta hai.
Sab barha diya:

| Cheez | Pehle | Ab |
|---|---|---|
| Business name | 19px | **24px** |
| Item / description | 11px | **13px** |
| Totals (subtotal waghera) | 11px | **14px, bold** |
| GRAND TOTAL | 11px bold | **17px, extra bold, upar line** |
| QR ke neeche invoice number | 9px | **14px bold** (alag line par) |
| QR image | 96px | **118px** |
| Thank you | 10px | **14px bold** |
| KOT item / qty | 14/16px | **16/18px** |

Sath hi text ka rang `#333` se `#000` — thermal printer par halka
grey aur bhi feeka chhapta hai.

---

## 22. Offline node kabhi update le hi nahi sakta tha

`GET_UPDATE.bat` yeh dikha raha tha:

```
Could not open input file: scripts\self_update.php
Aap ke paas pehle se latest build hai. Kuch karna nahi.
```

Teen alag bug ek sath:

**1. `scripts/` folder disk par hai hi nahi.** Sealed package sirf
`public/*.php` ke stubs banata tha. Magar chaar launcher files seedha
`php scripts\...` chalati hain: GET_UPDATE, INSTALL_UPDATE, RESET_NODE,
RESET_PASSWORD. Yani auto-update **kabhi chala hi nahi**.
→ Ab har script ka stub disk par jata hai (50 stubs), bilkul waise
jaise `public/api.php`.

**2. `dirname(__DIR__)` ghalat jagah point karta tha.** Sealed code ka
`__DIR__` package root nahi hota. `updates/available.txt` ek aisi jagah
bana raha tha jo launcher dekhta hi nahi.
→ Ab build ke waqt `dirname(__DIR__)` → `APP_ROOT` (asal package root).
Jo cheezein seal ke andar hain (`src`, `docs`, `approved_ui`) unke
raaste `sealed://` par jate hain; `config` ka apna qaida hai kyunke
seal mein us ka naam `offline.php` hai.

**3. `VERSION` sirf seal ke andar thi.** Node apna build parh hi nahi
pata tha — `installed:` khali aata tha, aur khali kabhi cloud ke build
ke barabar nahi hota.
→ Ab VERSION disk par bhi jati hai.

Sath hi `GET_UPDATE.bat` ka jhoota message theek kiya: portal tak
pohanch na ho to ab "latest build hai" **nahi** kehta, balki saaf
batata hai ke internet check karein.

**Test (dono raaste):**

| Halat | Nateeja |
|---|---|
| Cloud V98, node V97 | `installed: V97` / `available: V98` / `UPDATE_AVAILABLE` + `available.txt` |
| Portal band | `UPDATE_OFFLINE` + `offline.txt` (ghalat tasalli nahi) |
| Dono barabar | `UPDATE_NONE` |

---

## 23. Launcher ka info block

Pehle yeh dikhta tha:

```
Version        : 1.0.0
Contact number : +92 300 0000000
Website        : https://wabwar.com
Email          : support@wabwar.com
```

`1.0.0` **hard-coded** tha — chahe koi bhi build laga ho, launcher yehi
dikhata tha. Yani customer ya support kabhi nahi jaan sakta tha ke node
par asal mein kaunsa version chal raha hai.

Ab:

```
Product        : SmartPOS
Branch         : AKORWAL FISH POINT
Company        : Wabwar Software House
Version        : V98 build 2026-09-10      <- asli installed build
Contact number : +92 342 5095104
Website        : www.wabwar.pk
Email          : info@wabwar.pk
Licence expiry : 2026-10-05
```

**Version** ab package ki `VERSION` file se aata hai (`app.info` mein
bhi, aur launcher disk par maujood `VERSION` ko tarjeeh deta hai — is
tarah `INSTALL_UPDATE` ke baad foran naya number dikhta hai).

**Licence expiry** us business ki sab se nayi subscription se aati hai
(`tenant_subscriptions.expiry_date`) aur package banate waqt seal hoti
hai. Launcher rang bhi badalta hai:

| Halat | Rang |
|---|---|
| 15 din se zyada baqi | safed |
| 15 din ya kam | peela — `(12 din baqi)` |
| Guzar chuki | laal — `(KHATAM HO CHUKI)` |

Expiry counter par nazar aani chahiye, warna pata usi din chalta hai
jis din software band ho jata hai.

Yeh sab `APP_PHONE`, `APP_WEBSITE`, `APP_EMAIL`, `APP_VERSION` env
variables se badla bhi ja sakta hai.

---

## 24. Reports — audit aur 11 nayi reports

Maujooda 20 reports ko aapki list se milaya. Neeche audit ka nateeja:

### Pehle se maujood thin (9)

| Maanga gaya | Maujooda report |
|---|---|
| Daily Sales | `sales_summary` |
| Item/Product Sales | `sales_by_item` |
| Category Sales | `sales_by_category` |
| Payment/Collection | `payment_mix` |
| Expense | `expenses` |
| Stock/Inventory | `stock_movement`, `tracked_inventory`, `low_stock` |
| Profit/Margin | `profit_loss` |
| Tax Summary | `tax_summary` |
| FBR Tax | `fbr_sales` |
| Purchase (supplier-wise) | `supplier_buys`, `purchases` |

### Maujood NAHI thin — ab add ki gayi (11)

| Report | Kya deti hai |
|---|---|
| `invoice_detail` | Har bill ka poora record — mode, customer, cashier, items, subtotal, discount, tax, FBR no. |
| `waiter_sales` | Waiter-wise sale, bills aur **average bill** |
| `discounts` | Sirf discounts, **kis ne di** aur kitne % |
| `voids` | Sirf void bills, **wajah aur user** ke sath |
| `shift_closing` | Opening, sale, cash/card/credit, expenses, expected vs counted, **variance** |
| `wastage` | Item-wise zaya hua maal aur uski **qeemat** |
| `purchase_items` | Item-wise kharidari — qty, **avg rate**, amount |
| `customers` | Customer-wise bills, visits, last visit, **baqaya** |
| `fbr_reconcile` | POS bill vs FBR invoice — har bill par nateeja ("Match", "FBR tak nahi pohancha") |
| `fbr_summary` | Rozana/mahana: bills, bheje gaye, reh gaye, taxable, sales tax, zero-rated |
| `audit_activity` | Login, void, discount, refund, bill edit — kis ne kya kiya, kab, kis IP se |

**Kul ab 31 reports.**

### Do cheezein jo test ne pakrin

1. **`voids` mein `void_reason` column hai hi nahi.** Ab wajah `orders.notes`
   se aati hai, aur na ho to `audit_log` ki VOID entry se — yani jo wajah
   cashier ne likhi wo report mein aati hai.
2. **`inventory_items.last_cost` maujood nahi.** Wastage ki qeemat ab
   `avg_cost_per_stock_unit` se banti hai.

Dono asli data par chala kar tasdeeq ki: 31 ki 31 reports chalti hain,
aur nataij durust hain (discount `R-102` par, void `R-103` par wajah ke
sath, FBR reconciliation ne PENDING bill ko "FBR tak nahi pohancha"
mark kiya).

Har report pehle se maujooda `billWhere()` istemal karti hai — is liye
**cashier isolation** aur branch/date filter khud-ba-khud lagte hain,
aur CSV export bhi bina kisi extra kaam ke chalta hai.

---

## 25. Reports — dono verticals

### Retail ki reports pehle thin hi nahi

`approved_ui/retail/reports.html` sirf **demo data (localStorage)** par
6 chhoti tables dikhata tha — server se juda hi nahi tha. Yani
supermarket ke pass koi asli report thi hi nahi.

Ab `RetailReportService` hai: **30 reports**, sab asli `rtl_` tables
par, aur wahi shakl jo restaurant ki service ki hai — is liye UI aur
CSV export dono ke liye ek hi code chalta hai.

| Group | Reports |
|---|---|
| Sales (11) | Daily summary, by item, by department, by brand, by hour, payment/collection, **retail vs wholesale**, invoice detail, by cashier, by counter, basket analysis |
| Operations (3) | **Duplicate bill (reprints)**, audit/activity, held bills |
| Tax (2) | Tax summary, FBR reconciliation |
| Inventory (7) | Stock on hand, low stock/reorder, **dead stock**, expiry/near expiry, batch-wise stock, fast/slow movers, margin by item |
| Money (5) | Profit/margin, expenses, khata/receivable, **khata ledger**, credit sales |
| Customers (2) | Customer report, loyalty |

Kuch reports supermarket ke liye khaas hain aur restaurant mein maani
nahi rakhtin: **retail vs wholesale**, **duplicate bill reprints**,
**dead stock**, **expiry**, **khata ledger**.

### Restaurant

Pichli batch mein 11 add hui thin — ab **31 reports**.

### Dono par chala kar dekha

| | Reports | Chalin | CSV |
|---|---|---|---|
| Restaurant | 31 | 31 | ok |
| Retail | 30 | 30 | ok |

Do bug test se nikle aur theek hue:

1. `expenses` table mein `spent_on`/`category`/`method` columns hain hi
   nahi — asli naam `expense_date`, `category_id` (alag table),
   `payment_method`. Query theek ki.
2. Retail ki `fbr_reconcile` abhi POS ka apna record dikhati hai,
   kyunke retail bills mein FBR fields (`fiscal_status`,
   `fiscal_invoice_no`) abhi add nahi hue — yeh report ke note mein
   saaf likha hai, khamoshi se khali nahi chhoda.

### Retail reports screen

Ab server se chalti hai: date range (aaj / 7 din / yeh mahina / pichla
mahina), search, totals row, print aur **CSV download** — restaurant
jaisa hi.

File:// par kholein (bina login) to saaf kehti hai ke asli data ke liye
login chahiye — jhooti report nahi dikhati.

---

## 26. Auto-update ka download kabhi chala hi nahi tha

```
UPDATE_FAILED the portal did not return a package
              (check that this node is still linked)
```

**Wajah:** `self_update.php --download` package maangte waqt apna
`node_token` bhejta hai (wahi sync token jo har roz sync ke liye use
hota hai). Magar `offline-package` endpoint us token ko pehchanta hi
nahi tha — woh sirf `needLogin()` maangta tha, aur ek command-line
script ke paas browser session hota hi nahi.

Node ko ZIP ki jagah `{"ok":false,"message":"Login required"}` milta
tha. Script ne theek pakra ("yeh ZIP nahi hai") magar wajah bata nahi
sakti thi.

Yani: check kaam karta tha, **download kabhi nahi**.

**Fix:** ab endpoint do raaste qubool karta hai —

| Raasta | Kaun | Shart |
|---|---|---|
| Browser | Admin / Manager | login + cloud role |
| `node_token` | Offline node ka updater | token kisi zinda business se match kare |

Token se aaye to tenant aur site usi record se tay hote hain — session
ka koi dakhal nahi. Koi naya raaz nahi banaya gaya; wahi token hai jo
pehle se sync ke liye chal raha hai.

**Test (teeno halat):**

| Halat | Nateeja |
|---|---|
| Bina token, bina login | 401 `Login required` |
| Sahi node token | **200, 1.2 MB ZIP** |
| Ghalat token | 403 `This node is not linked to any business` |

Aur poora flow asli node se chala kar:

```
installed: V100 build 2026-09-11
available: V101 build 2026-09-12
UPDATE_DOWNLOADED update-V101build2026-09-12.zip (1.2 MB)
Close the software and run INSTALL_UPDATE.bat to apply it.
```

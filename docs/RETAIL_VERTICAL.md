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

---

## 27. Offline node par "No users found in this database"

Recovery tool ne asal masla saaf kar diya: node ke database mein **ek
bhi user nahi tha**. Is liye koi bhi password kabhi chal hi nahi sakta
tha — aur alamat "Invalid login" thi, jo bilkul gumraah karti hai.

### Wajah — do baatein mil kar

**1. Node ke users cloud se SYNC ke zariye aate the.** Package mein koi
login shamil nahi hota tha. Aur auto-sync us bug ki wajah se kabhi
start hi nahi hui (`RedirectStandardOutput` wala), is liye users kabhi
neeche aaye hi nahi.

**2. `ensure_v13_login.php` marker par andha bharosa karti thi.**

```php
if (!$force && is_file($marker)) { exit(0); }   // "sab theek hai"
```

Marker `storage/` mein bach jaye (database dobara bane, ya kabhi bhara
hi na ho) to yeh script chup-chaap skip kar jati thi — database khali
hone ke bawajood. Yani jo aakhri safety net tha, wo bhi nahi chala.

### Do fix

**a) Marker ab database se verify hota hai.** Agar users table khali ho
to marker bekaar samjha jata hai aur account dobara ban jata hai.

**b) Package ke andar owner ka login seal hota hai.** Ab `offline-package`
banate waqt us business ke owner ka record (id, email, naam aur
**bcrypt hash** — plaintext kabhi nahi) sealed config mein jata hai, aur
naya script `seed_node_owner.php` pehle boot par usay local database
mein daal deta hai.

Nateeja: **node pehle din se wahi login qubool karta hai jo portal par
chalta hai — sync se pehle bhi.**

### Test (khali database par, bilkul customer jaisa)

```
sealed owner: demo.restaurant@demo.local (hash 60 chars)
users pehle : 0
seed        : NODE_OWNER_CREATED demo.restaurant@demo.local
users ab    : 1
LOGIN portal wale password se : OK
ghalat password               : reject
dobara chalaya                : NODE_OWNER_ALREADY_PRESENT   (idempotent)
```

Script ehtiyat bhi barajti hai: user pehle se ho aur uska local password
maujood ho to **usay chherti nahi** — warna node par baad mein set kiya
gaya password mit jata.

---

## 28. INSTALL_UPDATE ne kabhi code badla hi nahi

Yeh sab se bara bug tha, aur sab se achhi tarah chhupa hua.

`INSTALL_UPDATE.bat` extract karte waqt kuch folders chhorta hai taake
customer ka data mehfooz rahe:

```php
$keep = ['data/','config/','runtime/','storage/','backup/','updates/'];
```

Iradah theek tha — `runtime/php/` aur `runtime/mariadb/` ke binaries
bade hain aur machine-specific. **Magar sealed package mein POORA
application code `runtime/app.sealed` mein hota hai.** `src/` disk par
maujood hi nahi.

Nateeja: update sirf stubs, `.bat` files aur **VERSION** replace karta
tha. Code jahan tha wahin rehta tha.

Yani node **naya version dikhata tha magar purana code chalata tha.**
Isi liye har fix ke baad "kuch farq nahi para" — aur har dafa poora
package dobara download karna parta tha.

### Maapa hua farq (asli package par)

| | VERSION badli? | `app.sealed` badla? |
|---|---|---|
| **Purana keep-list** | haan (V102) | **NAHI — purana code chalta raha** |
| **Naya keep-list** | haan (V102) | **haan** |

### Fix

Ab `runtime/` poora nahi chhorta — sirf woh hissa jo waqai machine ka
hai:

```php
$keep = ['data/','config/','storage/','backup/','updates/',
         'runtime/php/','runtime/mariadb/','runtime/data/'];
```

`runtime/app.sealed`, `app.key`, `boot.php` aur `app.info` ab replace
hote hain. Sath hi:

- Update ke baad **warm cache saaf** hoti hai, taake naya code foran
  lagay (purani cache stamp se bhi pakri jati, magar yahan saaf karna
  zyada seedha hai).
- Backup mein ab `app.sealed`, `app.key`, `boot.php` aur `VERSION` bhi
  jate hain — rollback mumkin rahe.

---

## 29. Naya invoice design + FBR per-property

### Receipt ka naya design

| Cheez | Pehle | Ab |
|---|---|---|
| Item table | sirf rows | upar **"Item Description"** aur **"Total Amount"** headers, neeche line |
| QR ke neeche | `INV:0002|AMT:12412|TAX:1712` (bara, bhara hua) | sirf **"FBR Invoice QR"** |
| Footer | "Thank you! Visit again." | **"Software by Wabwar Software House @ 0342-5095104"** (aur agar dukan ka apna footer set ho to woh upar) |

### QR ab sirf asli FBR bill par

**Pehle:** QR har bill par chhapta tha. FBR invoice number na ho to POS
khud `INV:...|AMT:...|TAX:...` bana kar QR mein daal deta tha.

Us QR ka FBR se **koi taalluq nahi tha** — koi sarkari scanner usay
pehchanta hi nahi. Aur jis dukan par FBR hai hi nahi, uske bill par bhi
"FBR Invoice QR" likha aata tha. Yeh customer ko gumraah karta hai aur
dukandar ke liye khatarnak hai.

**Ab shart ek hi hai:** asli FBR invoice number maujood ho. Na ho to
QR bhi nahi, "FBR Invoice QR" ka lafz bhi nahi.

### FBR chalu / band — Super Admin se

Do naye endpoints:

```
sa-fbr-status?tenant_id=...      -> is business par FBR chalu hai ya nahi
sa-fbr-toggle {tenant_id, enabled} -> chalu / band karo
```

Yeh maujooda `features_json` par hi chalte hain (wahi list jo baaki
modules ke liye hai). Alag switch banane se sach do jagah rakhna parta,
aur woh hamesha aage-peeche ho jata hai.

**Band karne par:**

| | Nateeja |
|---|---|
| `FiscalService::enabledForTenant()` | false |
| `availableHere()` | false |
| Bill par FBR number | nahi |
| Bill par QR | nahi |
| Fiscal service ko network call | **koi nahi** |

### Node tak khabar kaise pohanchti hai

`tenants` table sync ki pull list mein nahi hai, is liye Super Admin ka
faisla offline node tak pohanchta hi nahi tha — FBR band karne ke
bawajood node par chalta rehta.

Ab **sync handshake** ke sath `features` neeche jate hain aur node
unhein apni `tenants` row mein likh leta hai (`Sync::pullFeatures()`,
push/pull se pehle chalti hai). Agli sync par hi asar ho jata hai —
package dobara download karne ki zaroorat nahi.

**Test:**

```
A) FBR chalu : enabledForTenant = haan,  availableHere = haan
B) FBR band  : enabledForTenant = nahi,  availableHere = nahi
               submit() -> koi network call nahi
```

---

## 30. Restaurant POS — "Qty first" checkbox

Top-left par, home button ke saath ek checkbox: **Qty first**.

| Halat | Item par click karne se |
|---|---|
| Band (default) | Item seedha cart mein — 1 qty (purana tareeqa) |
| Chalu | **Quantity calculator khulta hai**; qty daal kar Apply dabate hi item cart mein chala jata hai |

Kuch counters par har item ki quantity alag hoti hai (fish, mithai, bulk
order). Wahan "pehle add karo, phir cart mein qty badlo" do qadam ka kaam
hai. Yeh checkbox usay ek qadam bana deta hai.

**Tafseelat:**

- Wahi calculator istemal hota hai jo weighted items ke liye pehle se
  tha — decimal (2.5) bhi chalta hai, aur 0.25 / 0.50 / 1.00 ke quick
  buttons bhi.
- Checkbox ki halat **browser mein yaad rehti hai**, taake cashier ko
  har shift par dobara na dabana pare.
- Weighted items (KG wale) par koi farq nahi — un par calculator pehle
  bhi khulta tha aur ab bhi khulta hai.
- Variant/pizza items par bhi koi farq nahi — un ka apna options modal
  hai jahan variant chunna zaroori hota hai.
- Qty 0 daalne par kuch add nahi hota.


---

## 31. FBR activation — button kahan hai

Pichhle round mein maine endpoints bana diye the magar **button nahi
banaya tha**. Ab bana diya.

**Super Admin → Businesses → us business ki row → `FBR` button.**

Modal dikhata hai:

- Abhi FBR **CHALU** hai ya **BAND** (rang se bhi — hara / laal)
- Chalu karne par kya hoga, band karne par kya hoga
- Ek hi button: *FBR chalu karein* / *FBR band karein*

### Andar kya chalta hai

Yeh maujooda `features_json` par hi chalta hai — wahi list jo "Features"
screen istemal karti hai (module key: `fbr`). Alag switch banane se sach
do jagah rakhna parta aur woh hamesha aage-peeche ho jata.

Farq sirf itna hai ke "Features" screen par `fbr` chalees modules ki
list mein ek checkbox tha — dhoondhna parta tha. Ab uska apna button
hai.

Ek ehtiyat: agar business par pehle koi module-hadd set na ho (matlab
"sab allowed"), to FBR band karte waqt poori list khud ban jati hai aur
usme se sirf `fbr` nikala jata hai — warna baaki 38 modules bhi band ho
jate.

### Test

```
pehle  : enabled=true,  unrestricted=true    (koi hadd set nahi thi)
BAND   : enabled=false, modules=38
CHALU  : enabled=true,  modules=39
```

### Node tak kaise pohanchta hai

Agli sync par. Handshake ke sath features neeche aate hain
(`Sync::pullFeatures()`), node apni `tenants` row mein likh leta hai.
Package dobara download karne ki zaroorat nahi.


---

## 32. Super Admin ke buttons ka audit

FBR ka button click par kuch nahi karta tha. Uske sath poore page ke
buttons dekhe — teen masle nikle.

### 1. FBR button chalta hi nahi tha (meri ghalti)

`bizFbr()` `modal()` ko bula raha tha. **Is page par `modal()` define hi
nahi hai** — yahan helper ka naam `sModal()` hai. Click par JS gir jati
thi aur kuch nazar hi nahi aata tha (koi error message bhi nahi, kyunke
exception chup-chaap console mein jata hai).

Mazay ki baat: yeh ghalti pehle bhi ho chuki thi, aur `bizRenew()` ke
upar uska comment likha hua tha — maine phir bhi dohra di.

→ Ab `sModal()`, aur design baaki dialogs jaisa: `.lead` subtitle,
`.note` box, `.tag green` / `.tag red` pill, footer mein Cancel + amal
wala button.

### 2. `.row` kahin define hi nahi thi

Har dialog ka footer `<div class="row">` istemal karta hai — magar yeh
class na `shared.css` mein thi na page ke apne style mein. Is liye
**har** dialog ke buttons neeche-neeche aate the, saath-saath nahi.

→ `.row` ab define hai (flex, gap, wrap), aur dialog ke footer mein
aakhri button khud dayen taraf chala jata hai.

### 3. `.grid` bhi define nahi thi + tooti hui `<label>` tags

Branding aur PMS ke popups `<div class="grid">` istemal karte hain jo
kahin define nahi thi — fields poori chaurai le kar bikhar jate the.

Sath hi **6 jagah `<label>` ko `</span>` se band kiya gaya tha**:

```html
<label>Display Name</span>      <!-- ghalat -->
```

Browser isay sambhal leta hai magar spacing aur click-to-focus dono
kharab hote hain.

→ `.grid` (do column, mobile par ek), `.modal-box label` ka apna style,
aur chhe ke chhe `<label>` tags durust.

### Har row-button ka endpoint check

| Button | Endpoint | Halat |
|---|---|---|
| Detail | `sa-business-detail` | ok |
| Renew | `sa-licence-get` | ok |
| Reset pass | `sa-admin-reset` | ok |
| Branding | (local data + `sa-branding-save`) | ok — koi GET endpoint hai hi nahi, by design |
| Features | `sa-features-get` | ok |
| **FBR** | `sa-fbr-status` / `sa-fbr-toggle` | **ab ok** |
| WhatsApp | `sa-wa-get` | ok |
| Suspend / Activate | `sa-business-toggle` | ok |

Aur `onclick` se bulaye gaye saare 9 functions maujood hain — koi
button aisa nahi jo kisi na-maujood function ko bula raha ho.

---

## 33. Whole app switched to English

All user-facing text is now English — no Roman Urdu left where a
customer can see it.

### What was changed

| Area | Files |
|---|---|
| Restaurant UI | `approved_ui/*.html` |
| Retail UI | `approved_ui/retail/*.html`, `*.js` |
| Super Admin | `super_admin.html` |
| Launchers | `*.bat`, `tools/*.ps1` |
| Server messages | `public/*.php`, `src/Services/*.php`, `scripts/*.php` |

Roughly **1,800 Roman Urdu words across 44 files**. Counts after the
pass: **0** visible lines in the UI, **0** message strings anywhere.

### How it was done

Whole sentences were translated, not word-by-word. A word-level pass
alone produced broken English ("all se sasta", "no bill nahi"), so each
of those was rewritten by hand afterwards.

### Two things that broke, and were fixed

1. **Apostrophes broke PHP and JS strings.** English possessives
   (`today's`, `user's`, `package's`) inside single-quoted strings caused
   parse errors in 5 files. Rewritten without apostrophes.
2. **Prose inside HTML** (not in quotes) was missed by the first passes —
   `offline_sync.html` and `tax.html` had whole paragraphs. Handled
   separately.

### Verified after the change

```
PHP lint      : every file OK
JS lint       : every file OK
super admin   : sign-in 200
RESTAURANT    : sign-in OK, 31 reports, report-run OK
RETAIL        : sign-in OK, 30 reports, report-run OK
```

### Still in Roman Urdu: code comments

The comments inside the source are untouched — deliberately. They are
developer notes, invisible to customers, and rewriting a few thousand
comment lines is pure risk with no user benefit. Say the word if you
want those converted too.

---

## 34. Renewal / activation key — four things fixed

### 1. The generated key was invisible

The key was being created correctly — it was rendered at the **bottom of
the licence form**, inside a modal capped at `max-height:88vh` with
`overflow:auto`. On most screens it landed below the fold, so it looked
as if the button had done nothing.

A key you cannot see is a key you cannot give to the customer.

→ The key now opens in **its own dialog**: 24px monospace, dashed
border, day count, and a Copy button.

### 2. The key could never be entered (the real deadlock)

Two separate gates made renewal impossible in exactly the situation it
exists for:

- `licence-key-apply` required `needLogin()`
- `LicenceKey::apply()` called `Scope::requireManagement()`

Once the licence expired, sign-in was blocked — so the customer could
never reach any screen to type the key into. They were locked out of
their own software **with the key in their hand**.

→ New `licence-activate` endpoint works **without a sign-in** on offline
nodes. Safe, because the key itself is the credential: HMAC-signed for
one business, single-use, self-expiring, and it can only extend a
licence — it cannot read or change any data. Rate-limited to 5 attempts
per minute; the cloud is excluded (online renewal goes through Super
Admin).

### 3. After expiry: activation screen, not the sign-in screen

Previously an expired node just refused the sign-in and left the user
staring at the login page.

→ Now the router sends **every page** to `activate.html`, and that page
is freed from the login requirement while the licence is blocked (it was
redirecting to login too — another dead end). Sign-in itself also blocks
now on offline nodes; before, `subscriptionBlock()` returned `null`
whenever the role was not cloud, so an expired node let people in and
half-worked.

Enter a valid key and work continues from exactly where it stopped.

### 4. Data removal 30 days after expiry

`scripts/licence_enforce.php`, run at every start-up:

| Situation | What happens |
|---|---|
| Licence valid | `LICENCE_OK expires … (n days left)` |
| Expired, within 30 days | Locked, **nothing deleted**, shows days remaining |
| Expired 30+ days | Business data removed, once, with a marker |

Deliberately **kept**: users, the tenant/site rows and the sync token —
so a key still reactivates the node instead of forcing a reinstall. The
**cloud copy is untouched**, so a renewed customer gets their data back
on the next pull. Applying a valid key clears the purge marker.

### Tested end to end

```
key generated            : NJ7G-07G0-A3HM-R57G-66X0-ANQ7
expired node, index.html : 302 -> /activate.html?expired=1
expired node, POS        : 302 -> /activate.html?expired=1
activate.html            : 200 (opens without sign-in)
sign-in while expired    : blocked, "Enter your activation key to continue"
key applied, no login    : ok, valid to 2026-12-10
same key reused          : blocked, "already been used"
grace  5 days past       : locked, 26 days left, nothing deleted
grace 40 days past       : 27 rows removed; users/tenant/sync token kept
purge run twice          : second run does nothing
```

---

## 35. Tax mode — inclusive or exclusive (both verticals)

A new setting in **Settings → Tax** for restaurant and retail alike.

| Mode | Shelf price 117, tax 17% | What the customer pays |
|---|---|---|
| **Inclusive** | 100 net + 17 tax | **117** — nothing extra |
| **Exclusive** | 117 net + 19.89 tax | **136.89** |

### Why this had to become a setting

Retail was deciding it from the **region profile** (PK/UK inclusive, US
exclusive), so two shops in the same country could not differ. The
restaurant was worse: the POS **browser** sent whatever `tax_amount` it
had calculated and the server saved it as-is — two tills could disagree,
and the number that reached FBR had no server-side check at all.

Now there is one setting, in one place, used by both verticals and by
FBR. The region still supplies the **default**, so nothing changes for an
existing shop until someone deliberately changes it.

### What changed underneath

- New `TaxMode` service — `current()`, `set()`, `split()`.
- **Retail:** `RegionProfile::isExclusive()` now prefers the saved
  setting and falls back to the region.
- **Restaurant:** `PosService::finalize()` computes the tax **on the
  server** from the mode and rate instead of trusting the client. If an
  older POS sends no rate, its own figure is still accepted — otherwise
  old builds would suddenly bill without tax.
- Admin only (`isManager`), because this changes the figures on every
  bill and on every FBR invoice.

### FBR

The digital invoice carries taxable value and tax amount separately. Get
the mode wrong and both are wrong on every invoice — so this setting
decides what is *reported*, not just what prints. The settings screen
shows FBR status right next to the control, and warns plainly when FBR
is off for that business.

### Tested

```
split 117 @17%   INCLUSIVE : net 100.00  tax 17.00  gross 117.00
                 EXCLUSIVE : net 117.00  tax 19.89  gross 136.89
retail bill      INCLUSIVE : subtotal 100.00  tax 14.53  total 100.00
                 EXCLUSIVE : subtotal 100.00  tax 17.00  total 117.00
restaurant 1000  INCLUSIVE : tax 145.30  customer pays 1000.00
                 EXCLUSIVE : tax 170.00  customer pays 1170.00
save/read (both verticals, over HTTP) : OK
invalid value                          : rejected
```

---

## 36. The tax-mode setting did nothing on the restaurant POS

A customer bill showed it plainly:

```
Settings : Price mode = "Tax included in the price", cash tax 16%
Bill     : Subtotal 3,900 · Sales Tax 624 · GRAND TOTAL 4,524
```

3,900 × 16% = 624 added **on top** — exclusive behaviour, with the
setting on inclusive.

### Why

The setting was wired into the services and into retail, but the
**restaurant POS runs its totals in the browser**, and that code was
never touched:

```js
var tax = (base+sv) * orderTax/100;
return { ..., grand: base+sv+tax };     // always exclusive
```

The POS also never received the mode (`settings-get` did not send it)
and sent only `tax_amount` on finalize — no rate — so the new
server-side check fell back to the browser's figure every time.

End to end, the setting changed nothing for a restaurant.

**My mistake:** I tested `TaxMode::split()` and `RegionProfile::billTotals()`
and called the feature done. Neither of those is on the restaurant POS
path. A service that computes correctly is not a feature that works.

### Fixed

- `settings-get` now returns `tax_mode` (the POS already calls it for rates).
- `totals()` respects the mode: inclusive extracts tax and leaves the
  grand total equal to the menu price.
- The POS sends `tax_rate` on finalize, so the server recomputes instead
  of trusting the browser.
- Receipt wording: inclusive prints **"Sales Tax (incl.)"**, because
  "Subtotal + Sales Tax = GRAND TOTAL" is untrue when the tax is already
  inside the subtotal.

### That same bill, after the fix

| | Subtotal | Sales Tax | Grand total |
|---|---|---|---|
| Before (exclusive, despite the setting) | 3,900 | 624.00 | **4,524.00** |
| After (inclusive, as set) | 3,900 | 537.93 | **3,900.00** |

Net value 3,362.07 — that is what goes to FBR as the taxable amount.

---

## 37. EXCLUSIVE had no effect either — same bug, second door

Inclusive worked after the last fix. Switching to "Tax added at the
till" changed nothing: no extra tax was added.

### Why

The POS builds its `SET` object from **`pos-boot`**, not from
`settings-get`:

```js
if (BOOT.settings) { SET = BOOT.settings; ... }   // line 700
```

Last round I added `tax_mode` to `settings-get`. The POS does call that
endpoint — but only for the receipt footer and paper size. `SET` is
overwritten from `pos-boot`, which did not carry the mode. So
`SET.tax_mode` was `undefined`, and:

```js
function taxInclusive(){ return String(SET.tax_mode||'INCLUSIVE')... }
```

…fell back to INCLUSIVE every time. Inclusive appeared to work for the
right reason by accident; exclusive could never work at all.

**The lesson I keep relearning on this feature:** I verified the
endpoint returned the value instead of verifying the POS *used* it. Two
rounds, same class of mistake.

### Fixed

- `pos-boot` and `pos-settings` now both return `tax_mode`.
- Verified through the endpoint the POS actually reads:
  `set EXCLUSIVE -> pos-boot returns EXCLUSIVE`.

### Tax % on the bill

Now printed next to the amount, in both verticals:

```
Sales Tax 16%            PKR 624.00       (exclusive)
Sales Tax 16% (incl.)    PKR 537.93       (inclusive)
```

Retail prints the rate too, and only when every line on the bill shares
one rate — with mixed rates a single percentage would be misleading, so
it is left off.

### The real POS function, both modes

```
Bill: 3,900 of goods, cash tax 16%
INCLUSIVE   Subtotal 3,900   Sales Tax 16%   537.93   GRAND 3,900.00
EXCLUSIVE   Subtotal 3,900   Sales Tax 16%   624.00   GRAND 4,524.00
```

---

## 38. Shortcut bar on Sale Point, and the missing FBR QR

### Shortcut bar

The retail POS had a shortcut strip along the bottom; the restaurant POS
never did — its shortcuts only existed inside the F12 dialog. A thing
nobody can see is a thing nobody learns.

Added the same strip to Sale Point: F1 new bill, F2 kitchen, **F3 charge**
(highlighted), F4 hold, F6 duplicate, F7 bill type, F9 void, F10 new
item, plus Ctrl+K, +/−, Del, and F12 for the full list.

The buttons are clickable too — they dispatch the same keyboard event, so
a tablet user taps what a counter user presses. One handler, two routes.

### Why the QR was missing

The plumbing was fine — `pos-finalize` returns `fbr_no` and the receipt
reads it. The check on the actual node:

```
fiscal : { enabled: true, tenant_on: true, provider: 'NONE' }
```

FBR was switched on in Super Admin, but **no fiscal provider was chosen
on the node**, so nothing was ever submitted and no invoice number came
back. The receipt correctly printed no QR — silently, which is why it
looked like a bug.

### What changed

**QR now prints whenever FBR is genuinely running on that counter:**
feature ON, offline node, **and** a provider chosen.

| Feature | Provider | Number | QR |
|---|---|---|---|
| on | FBR | FBR-123 | **yes** — the real number |
| on | FBR | none yet | **yes** — marked `PENDING` |
| on | NONE | none | no |
| off | — | — | no |

Only checking "feature ON" was not enough: a shop that never set FBR up
would print a `PENDING` QR on every bill forever, which is a lie on
paper.

**And the cashier is told.** Provider not set → one toast, once per
session, pointing at Tax / Digital Invoice. Provider set but the service
did not answer → the bill prints and the message names the reason.
Nothing fails in silence any more.

---

## 39. Opening / closing the account from Sale Point

A cashier was given exactly two modules — **Opening & Closing Shift**
and **Sale Point**. The POS opened, a new bill started, and on saving it
said *"open account first"* — with no way anywhere on the screen to open
one.

### Why

The controls existed, but only as a small text link inside the status
strip (`Shift closed · Open`) and a **My Shift** button that is injected
next to `#dupBtn` — and that button is hidden for some roles, so the
insert had nothing to anchor to. Everything else on the screen was
billing, which is precisely the thing that cannot start yet.

The gate was right; the door was hidden.

### Now

The shortcut bar carries the account control as its **first** item, and
it is impossible to miss:

| State | Button |
|---|---|
| No shift | **red** — `F11 Open account` |
| Shift open | **green** — `F11 Close account` |

- Shown to anyone with the `shift` module (or a manager) — no role
  guessing.
- **F11** does the same from the keyboard.
- Repainted on every strip render, so it always matches reality.

### Closing prints the report

Closing the account now **opens the full shift report for printing by
itself** — category-wise sales, item lines, payment mix, cash
reconciliation and a signature line. The dialog still appears for a
reprint or a cash handover.

Previously the cashier had to notice the Print button in the dialog; if
they clicked Done, the shift closed with no paper at all.

### Tested as that exact cashier

```
login          : ok, modules ['shift','pos']
restaurant_pos : 200, account button present
shift-open     : ok  S-260912-0DFC, Counter 1, opening 5000
shift-current  : returns the open shift
shift-preview  : report with expected cash
shift-close    : ok, report carries shift_id (so auto-print can find it)
```

---

## 40. The account button broke the whole screen

Symptoms from the counter: the shift was clearly open (`Shift S-260910-C5EE
Close` in the strip), but the new button still read **"Open account"** in
red — and the menu area was completely blank, no items, no categories.

### One cause behind both

`hasMod` is not a global. It is a **local** variable inside the boot
function:

```js
var mods = (CAN.modules||[]);
var hasMod = function(k){ return CAN.manage || mods.indexOf(k)>=0 };
```

I called it from `paintAcct()`, which runs from `renderStrip()` — a
different scope. Every render threw a `ReferenceError`.

And because `renderStrip()` is the **first** call inside `renderAll()`:

```js
function renderAll(){ renderStrip(); renderCats(); renderGrid(); renderCart() }
```

…the strip was already written to the DOM, then the exception killed the
rest. So the strip looked fine, the items never drew, and the button
never got repainted. Two symptoms, one line.

**What I should have done:** the earlier test only checked that the
markup was present in the page. Markup present is not code running.

### Fixed

- `canMod()` is now a proper global helper next to `CAN`.
- `paintAcct()` is wrapped in try/catch — one button must never take the
  screen down with it.

### Verified by running the real functions

```
cashier [shift,pos]  shift null -> "Open account"  (red)
                     shift open -> "Close account" (green)
modules [pos] only   -> button hidden
manager              -> visible
keybar missing       -> no exception (render continues)
CAN undefined        -> no exception
page                 -> canMod present, old scope bug gone
pos-boot             -> 13 products, 5 categories
```

---

## 41. "No open shift" — the POS was showing someone else's shift

The strip said `Shift S-260910-C5EE · Close`, the button said **Close
account**, and pressing it answered **"No open shift"**. The cashier
could neither open an account nor close one.

### Cause

`PageData::posBoot()` picked up **any** open shift in the branch:

```php
SELECT id,shift_no FROM cashier_shifts
 WHERE site_id=? AND status='OPEN' ORDER BY opened_at DESC LIMIT 1
```

No `cashier_user_id`. So an old shift left open by the admin — dated
**10 September**, two days earlier — was handed to a cashier who had no
shift of their own.

Meanwhile `shift-preview` and `shift-close` both scope correctly:

```php
WHERE site_id=? AND cashier_user_id=? AND status='OPEN'
```

Boot said "you have a shift", the close said "you don't". Both were
answering honestly; they were answering different questions. The cashier
was stuck between them.

### Fixed

- `posBoot()` now carries the same condition: **only my open shift**.
- The client also self-heals: if the server replies "no open shift", the
  POS clears its own state and opens the shift dialog instead of leaving
  the cashier arguing with a button.

### Reproduced, then verified

```
admin leaves S-260910-C5EE open (2 days old)
cashier signs in   -> BOOT.shift: None          (was: the admin's shift)
cashier opens      -> S-260912-BD9C, Counter 2
BOOT.shift         -> S-260912-BD9C             (their own)
shift-preview      -> ok
shift-close        -> ok
```

### Note on the build

The screenshot still showed **POS v33** while the fixes were in v34/v35.
The on-screen version is now bumped with every POS change for exactly
this reason — so "is the fix even loaded?" can be answered by looking at
the screen instead of guessing.

---

## 42. Super Admin: Command Guide + Usage Analytics, and POS speed

### Command Guide (new page)

**Super Admin → Command Guide.** Every console command grouped by the
job it does, each with a copyable example and a note on *when* to use
it — not just the syntax:

| Group | Covers |
|---|---|
| Everyday | list, info, users, permissions |
| Access | suspend, activate, reset password, FBR on/off, features |
| Data — reversible | backup, reset txn, purge by type, purge before a date |
| Data — permanent | reset full, delete |
| Creating | create, demo |
| Offline branches | nodes, sync, resync, tombstones |
| Checking | audit, query, tables, selftest |

The console's own `help` output is appended underneath, pulled live —
so a command added later shows up even if this guide is not updated.

### Usage Analytics (new page)

Answers the question the Businesses list never did: **who is actually
using the software.**

- **Usage %** = days with at least one bill ÷ days in the window.
  A sign-in with no bill is not usage.
- A business created 5 days ago is measured over **5** days, not 30 —
  otherwise every new customer looks like a failure.
- Health: HEALTHY (70%+), LIGHT, RARE, SLOWING (idle 7d), AT_RISK
  (idle 14d), NEVER_USED.
- Per business: active days, bills, bills per active day, last bill,
  idle days, users, modules touched. CSV export included.

Restaurant and retail bills live in different tables; both are counted.

### POS speed — measured, not guessed

On demo data (13 items) everything looked fine. At a real month's volume
(**413 menu items, 6,000 orders**) `pos-boot` took **50 ms**, and it grew
with the data. Profiling showed 10 queries — 45 ms of it in two:

| Query | Problem |
|---|---|
| next bill number | `ORDER BY created_at` → **filesort over 2,895 rows** |
| today's bills | five JOINs, then sort, to show 50 rows |

**Fixes**

1. Two indexes on `orders` — `(site_id, business_date, created_at)` and
   `(site_id, business_date, closed_at)`. Same class of bug that was
   fixed in retail earlier; the restaurant side still had it.
2. Today's-bills query now **picks the 50 ids first, then joins**. It was
   joining 2,895 rows to display 50.

| | Before | After |
|---|---|---|
| `posBoot()` | 50 ms | **9 ms** |
| `pos-boot` over HTTP | ~55 ms | **21 ms** |

Same output: 413 products, 50 bills.

**Note:** these indexes are created by `migrate_retail.php`, which runs
on every deploy and start-up — nothing manual to do. On a busy shop the
gap widens, because the old queries got slower as orders accumulated
while the indexed ones do not.

---

## 43. Usage Analytics — design fixes

The page worked; it did not look right. Four problems, all mine.

### 1. The table class did not exist

I wrote `<table class="tbl">`. Every other table on the page uses
`class="table"` — `tbl` is defined nowhere. With no styling the columns
collapsed onto each other, which is why the screenshot showed
**"Restaurant67%"** running together.

### 2. Numbers were left-aligned under centred headers

Bills, Idle, Users, Modules and Usage now carry `class="num"` on both
the header and the cell, with `tabular-nums` so the digits line up.

### 3. LIGHT had no pill background

I used `tag amber`. That class does not exist either — `shared.css` has
`green`, `orange`, `red`, `danger`, `neutral`, `info`, `blue`, `brand`.
An undefined class renders as bare text, so LIGHT looked broken next to
a properly filled NEVER USED.

Health colours now: HEALTHY green · LIGHT / RARE / SLOWING orange ·
NEVER_USED / AT_RISK red.

### 4. All four KPI cards were red

The default `.kpi` accent is the brand colour, which is red here. Every
card shouted equally, so the eye landed nowhere. Now: the first two are
informational (blue), average usage is green or amber on its own value,
and "Need attention" is amber only when it is not zero.

Also: the usage bar now sits **under** the percentage inside its own
fixed-width cell instead of stretching into the next column.

### Verified by rendering the page, not by reading it

```
JS errors     : 0
table class   : table usage-tbl
header align  : -,-,num,num,num,num,-,num,num,num,-
row cells     : biz,-,num usage-cell,num,num,num,-,num,num,num,-
bar present   : true
health pill   : <span class="tag orange">RARE</span>
kpi cards     : kpi info | kpi info | kpi warn | kpi warn
Command Guide : 25 commands in 7 groups, 0 errors
```

**The lesson, again:** I checked that the markup was in the page. I did
not check that the classes I used were real. Two of the four bugs were
simply invented class names.

---

## 44. POS: delete dialog, blank grid, held bills, categories

### 1. The delete confirmation had no styling

`delete_kit.js` builds its popup with `.modal > .dialog > .dialog-head /
-body / -foot`. Those classes live in `shared.css` — and **Sale Point
does not load shared.css**; it has its own CSS (`.ov/.dlg/.dh/.db/.df`).

So on every other page the delete dialog looks right, and on the POS it
rendered as bare unstyled text. Those classes are now defined in the POS
stylesheet, matched to its own dialogs.

### 2. The item grid went blank after a delete

`cat` holds the selected category **name** and survives `boot()`. Delete
the last item in a category and that category disappears from the boot
payload — the filter stays pointed at a category that no longer exists,
so `visible()` returns nothing. The chip to click back to "All" was gone
too. Only a page refresh reset it.

→ After boot, if the selected category is no longer there, the filter
falls back to **All**.

Found alongside it: `boot()` called `setInterval()` twice on every run,
and `boot()` runs after every delete and refresh. The timers piled up
and the POS grew heavier the longer it stayed open. They are now created
once.

### 3. Held bills could not be deleted

There was **no cancel option at all** — only Resume. The cashier would
resume a held bill, clear the items, and assume it was gone; the order
stayed open on the server, sat in the Hold list forever, and brought all
its items back on the next resume. That is exactly the two bills that
would not go away.

→ New `pos-hold-cancel` endpoint and a **Cancel** button on each held
bill, with its own dialog (reason + amount + item count). Items already
sent to the kitchen need a manager password — that food has been cooked
and cannot vanish without a record. The bill is marked VOID and audited.

### 4. Categories could not be deleted

Also missing entirely. New `menu-category-delete`:

| Case | Behaviour |
|---|---|
| Empty category | Deleted |
| Has items, no choice made | **Refused**, with the count and the name |
| Has items, `move` chosen | Items move to **General**, then delete |

Soft delete (`deleted_at`), so old bills still report correctly. The
category list with a Delete button now sits in the POS "New item →
Category" tab, and the boot payload carries category ids.

```
delete empty : ok
with items   : refused — 3 items are still in "BBQ"
move=true    : ok, deleted BBQ, moved 3
hold bad id  : This bill was not found
```

### 5. Offline category creation

Tested on a real offline node (local role, sealed config): creating a
category works, the duplicate check works, and creating one with a
printer station works. Nothing reproduced here — the exact error message
from that node would be needed to go further.

---

## 45. Deleting a menu item — simplified

### What was wrong

Deleting an item went through `DeleteKit`, which asked **every user for a
reason** before it would proceed. The owner, standing at his own counter,
had to justify removing an item he had just mistyped.

Worse, a cashier could never complete it at all. Even with the correct
manager password the server refused:

```
CASHIER + correct manager password -> "You do not have permission to menu item"
```

`DeleteService::mayDelete()` checks the **module** permission, which a
cashier does not have — and rightly should not. But that check ran even
when a manager had just stood there and typed their password. The two
layers never spoke to each other.

### Now

| Who | What happens |
|---|---|
| **Admin / Manager** | One short confirmation, then gone. No reason, no password. |
| **Anyone else** | Manager password — the same one used for voids. |

The confirmation for an admin exists only because a delete cannot be
undone; it takes one keystroke (the Delete button is focused).

### Server side too

The screen asking for a password is not a control — anyone can call the
API directly. `entity-delete` now enforces it: a non-manager must supply
a valid manager password or the request is refused.

And when that password **is** valid, `DeleteService::$managerVerified`
is set for that single request, so the module-permission check no longer
blocks a delete a manager has just authorised in person.

```
ADMIN, no password        -> deleted
CASHIER, no password      -> "A manager password is required to delete this"
CASHIER, wrong password   -> "Incorrect manager password"
CASHIER, correct password -> deleted
```

If the item is in use (an open bill), the server still refuses and the
POS shows the real reason rather than a vague "failed".

---

## 46. Delete said "deleted" while the item stayed, and two separate category lists

### 1. "Spicy Fish Fillet deleted" — but it was still there

Server-side delete is correct; verified end to end (13 items → 12, the
row gone from the next `pos-boot`). So the toast was telling the truth
about the API call and lying about the screen.

Two things were wrong on the client:

**It never checked.** `runDelete()` showed the success toast and called
`boot()`. Whether the item actually left the list was never verified. If
a **second item shares the name** — easy to create twice at a busy
counter — one goes and the other stays, and the message still says
"deleted".

Now the POS re-reads the menu and looks for that exact id. If it is
still present:

```
Deleted — but an item with this name is still in the menu (a duplicate?)
```

**`boot()` was too heavy for this.** It rebuilds everything, and with an
empty cart it opens the **New Bill** dialog — which is why a bill-type
popup appeared right after a delete (visible in the screenshot). Delete
now calls `refreshMenu()`: products and categories only. Cart, shift and
bill are left alone.

### 2. The POS and "Menu & Categories" had different categories

Not a sync problem — **two unrelated lists**.

The POS reads `menu_categories` (FISH, Mutton…). The Menu & Categories
page read a **hardcoded array** in `module_config.js`:

```js
options:['Pakistani','Pizza','BBQ','Fast Food','Drinks','Desserts','Sides']
```

Nothing to do with the database. That is why the Category dropdown on
that page was useless for a shop whose categories are FISH and Mutton.

**Fixed:**
- New `menu-categories` endpoint — one list, read by both.
- The restaurant form engine now accepts `options` as a **function**, so
  the dropdown is filled from the server instead of from code.
- A **+** next to the Category dropdown creates one without leaving the
  form (same as the retail product form).

```
POS       : BBQ, Karahi, Rice, Breads, Beverages, General
Menu page : BBQ, Karahi, Rice, Breads, Beverages, General
same?     : True
+ created : appears in both immediately
```

---

## 47. The item was deleted; the browser was showing a cached list

The new honest message appeared — "still in the menu (a duplicate?)" —
and it was wrong about the cause. The check compares by **id**, so the
same row was coming back, not a second item with the same name.

### Cause

API GET requests were cacheable at both ends:

- The client (`api()` in the POS, `db_api.js`, `retail_api.js`) sent no
  cache-buster.
- The server sent **no `Cache-Control`** on JSON responses.

So the browser was free to answer `pos-boot` from its own cache. After a
delete the POS asked for the menu again and got the **old list back** —
with the item still in it. The row was gone on the server the whole
time, which is why `Ctrl+Shift+R` "fixed" it and why every test through
curl passed.

This explains more than the delete: any change made in one place and not
appearing until a hard refresh had the same root.

### Fixed on both ends

```php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
```

and a `&_=<timestamp>` on every GET, in all three clients — POS,
`db_api.js` (admin pages) and `retail_api.js`.

```
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
Pragma: no-cache

before : 13 items
delete : DELETED
after  : 12 items, item gone
message: "Chicken Karahi (Full) deleted"
```

The duplicate warning stays, but it can now only fire for a genuine
duplicate — and it says plainly what to do rather than guessing.

**What this cost:** the previous round I added a message that blamed
duplicates on a hunch instead of checking why the same id came back. The
message was honest about the symptom and wrong about the cause.

---

## 48. When a deleted item still shows — a diagnostic instead of a guess

The item stayed after a hard refresh, so it was not the browser cache
either. Server-side delete works on every system I can test (13 items →
12, the row gone from the next `pos-boot`), so the answer is in that
installation's data, not in the code path.

Rather than guess a third time, `scripts/diagnose_item.php` reports what
is actually there. Read-only.

```
php scripts/diagnose_item.php "Spicy Fish"
```

It prints every matching row across every branch — id, active, on-POS,
`deleted_at`, how many bill lines use it, which site — then what the POS
would show, then a plain reading:

| It finds | It says |
|---|---|
| One copy deleted, another live | **DUPLICATE** — delete the live one, id shown |
| Rows only on another site | Belongs to another **branch** — sign in there |
| Every copy has `deleted_at` | Already gone; the screen is stale |
| Live and used in bills | Delete is refused on purpose — mark it **inactive** |
| Live and nothing blocking | The delete did not reach it; note the exact message |

On an offline node it adds the check that matters there: `menu_items` is
a table the node **pulls from the cloud**. Delete it on the node while
it still exists online and the next sync can bring it back. It also
prints how many delete signals are queued.

Sample run against a deliberately duplicated item:

```
Rows found: 2
  895de117  Spicy Fish Fillet  active yes  on POS yes  — (LIVE)
  834e00a4  Spicy Fish Fillet  active yes  on POS yes  2026-09-16 11:33:55

What the POS shows: 1 row — Spicy Fish Fillet (895de117)
Reading: DUPLICATE. One copy is deleted, another is still live.
```

**Why a tool and not another fix:** the last two rounds I shipped a
cause I had not verified — first duplicates, then the cache. Each was
plausible and neither was confirmed against that installation. This
prints the facts in one run.

---

## 49. Found it: `ok:true` did not mean "deleted"

Cloud, not offline. Not the cache. The cause was in my own client code.

`entity-delete` returns HTTP success for a request it **refused**:

```json
{ "ok": true,
  "result": "BLOCKED",
  "blockers": ["used in 1 bill line(s) - marking it inactive is safer than deleting"],
  "can_deactivate": true, "can_force": true }
```

`runDelete()` only looked at `r.ok`. So for any item that had **ever been
sold**, the POS announced "deleted" and the item stayed exactly where it
was. Every item I tested against was a fresh one with no bill lines —
which is why it worked here every single time and never on their counter.

`ok` means the request was handled. `result` says what was decided:

| result | meaning |
|---|---|
| `DELETED` | gone |
| `DEACTIVATED` | hidden, data kept |
| `BLOCKED` | not deleted — `blockers` says why |

And BLOCKED is **correct** behaviour: the item's name is printed on old
bills. It should be hidden, not erased.

### Now

A blocked delete opens a dialog that states the reason and offers the two
real choices:

- **Mark inactive** — off the POS and the menu; old bills and reports
  untouched. This is the right answer nearly always.
- **Force delete** — also strips it from those old bills; reports for
  those days change. Manager password required, warning shown, no undo.

```
sold item, auto       -> BLOCKED, "used in 1 bill line(s)"
                         can_deactivate: yes, can_force: yes
        deactivate    -> DEACTIVATED, is_active=0
POS afterwards        -> 12 items, the item is gone from the grid
```

### Three rounds of wrong guesses

Duplicates, then the browser cache, then a diagnostic script. The first
two were plausible stories I never confirmed on the failing system. The
answer was two lines above the code I kept editing: I was reading `ok`
and ignoring `result`, which the server had been sending all along.

What the earlier work did leave behind, and is worth keeping: the
`no-store` headers and GET cache-busting (real, if not this bug), and
`item-debug` / `diagnose_item.php`, which now print the database's own
account instead of inviting another guess.

---

## 50. Deleting an item must not touch old bills — and it never had to

The force delete failed with a raw database error:

```
SQLSTATE[23000] ... CONSTRAINT `fk_oi_menu` FOREIGN KEY (`menu_item_id`)
```

The customer's point settles the design: **deleting a menu item means it
will not be sold again. It says nothing about what was already sold.**

### The blocker was pushing people toward the only dangerous option

`menu_item` already deletes **soft** (`deleted_at`): the row stays, the
foreign key stays, every old bill prints exactly as before, and the item
simply disappears from the POS and the menu. Nothing about that can harm
history.

Yet bill lines were listed as a *dependency*, so a plain delete on any
item that had ever sold came back BLOCKED — and the dialog then offered
**Force delete**, which is the one operation that really does damage old
bills (and, as the screenshot shows, cannot even complete because of the
foreign key).

The guard meant to protect history was steering people into the one
action that breaks it.

That dependency is gone. Delete on a sold item now does what it always
should have:

```
item: sold in a bill
delete     -> DELETED
menu row   -> deleted_at set, row still present
bill lines -> 1, untouched
POS        -> item gone from the grid
```

### Two more fixes from the same screenshot

**Admins were asked for a manager password.** On force delete the POS
prompted every user. An admin already proved who they are at sign-in.
Now the prompt only appears for someone who is not a manager.

**Raw SQL errors reached the counter.** A foreign-key violation was
printed verbatim in the toast. It is now translated:

> This item is attached to older records (usually bills that were already
> printed). Those cannot be broken. Use "Delete" normally — it hides the
> item from the POS and leaves old bills exactly as they are.

---

## 51. My fix never shipped: two copies of the same file

The Category dropdown still listed `Pakistani, Pizza, BBQ…` after V122,
where I had supposedly replaced it with the real categories.

The edit was real. It was in the wrong file.

```
./public/module_config.js          38516 bytes   <- the browser gets this one
./approved_ui/module_config.js     40695 bytes   <- I edited this one
```

Production's document root is `public/`, so Apache serves `.js` straight
from there and never reaches the router. Five files exist in both places
and differ:

```
access_store.js  live_store.js  module.js  module_config.js  shell.js
```

And there is no single winner: `shell.js` is newer in `public/`,
`module.js` was newer in `approved_ui/`. Copying one over the other
would have broken something else.

### Fixed

- The change applied to the **served** copies: `public/module.js` and
  `public/module_config.js`.
- `scripts/check_assets.php` lists every duplicated pair that has
  diverged, with sizes and dates, and says plainly that the browser gets
  the `public/` copy. It reports; it does not overwrite, because the
  right side differs per file.
- `module.js`, `module_config.js` and `shell.js` were also missing from
  the router's `?b=` cache-bust list, so even the right edit could sit
  behind a one-hour browser cache. All three are on the list now (v15).

```
served module_config.js -> menu-categories call present, hardcoded list gone
served module.js        -> "+" button code present
menu page categories    -> BBQ, Karahi, Rice, Breads, Beverages, General
POS categories          -> BBQ, Karahi, Rice, Breads, Beverages, General
```

A fallback was added too: if the category call fails, the dropdown falls
back to the categories already on the loaded items rather than showing
an empty list that makes the form unsubmittable.

**The lesson:** "I changed the file" is not "the browser runs it". Two
copies of a file with the same name is a trap that will keep catching
people; `check_assets.php` at least makes it visible.

---

## 52. Shift opening, and handing a shift to the next cashier

### Opening asks one question now

The Counter field is gone. A shift belongs to the **user who is signed
in**, not to a counter, and asking the cashier to type a counter name
created a problem out of nothing: everyone typed "Counter 1", so the
second cashier was told *"Counter 1 already has an open shift"*.

The server picks the first free counter itself (Counter 1, Counter 2 …).
Two cashiers opening at once now land on separate counters without
either of them thinking about it.

### Handover on closing

Two situations, and the difference is the money:

**Pending bills only.** Unpaid and open bills move to the chosen user.
The cash already taken stays with the cashier, who closes normally and
hands their drawer to the manager.

**Everything, including amounts.** All bills *and* their money go to the
next user. The outgoing cashier does not close with cash — their shift
closes at zero, and the incoming user closes later including these
amounts.

Both write on each bill who handed it to whom and when, so tomorrow
nobody has to guess whose money it was.

### The flaw underneath, which had to be fixed first

The shift report counted by **time and site**: every bill closed at that
site since the shift opened. Fine with one counter. With two cashiers
side by side, **both closing reports showed the same site totals** —
each counting the other's sales as their own. Cash accountability did
not exist, and no handover feature could have worked on top of it.

Bills are now tied to the shift by `shift_id`. That is what makes
handover real: move the bill's shift and its money moves with it. Old
bills without a `shift_id` still fall back to the time window.

```
start                one: 1 bill / 1500     two: 0 bills / 0
pending handover  -> 2 pending bills moved; cash unchanged on both
all handover      -> 1 bill and 1,500.00 moved
after             one: 0 bills / 0        two: 1 bill / 1500
```

Also checked: handing over to yourself is refused, and so is a handover
with nothing to move.

---

## 53. The English translation silently removed the way out of a stuck shift

A cashier could not open a shift:

> Pichli shift S-260910-16AB ka cash clear failed. Pehle usay clear please.

Two faults in one screen.

### The "Clear Cash Now" button had disappeared

The POS decided whether to offer that button by **matching the words of
the server's message**:

```js
if (/cash clear nahi/i.test(r.message || '')) { ...show the button... }
```

When the app was translated the message became "cash clear failed". The
test stopped matching, the button stopped appearing, and the cashier was
left with a refusal and no way forward — no billing at all until someone
touched the database.

The server now returns a **flag**:

```json
{ "ok": false, "needs_clear": true, "shift_id": "...", "amount": 4500 }
```

Text gets translated; flags do not. The button is driven by
`needs_clear` and carries the shift id and amount with it.

```
open shift        -> ok:false, needs_clear:true, S-TEST-CLR, 4500
clear cash        -> ok:true, cleared 4500
open shift again  -> ok:true, S-260916-C12B
```

### "Opening &amp; Closing Shift"

The title was written with `&amp;` and then passed through `panel()`,
which escapes its arguments — so the ampersand was escaped twice and
printed literally. Fixed here and in "Backup & Restore", which had the
same mistake.

### Leftover Roman Urdu — in the copies that are actually served

47 user-facing strings were still in Roman Urdu, most of them in
`public/*.js`. The translation pass had run over `approved_ui/`, and as
§51 established, the browser is served the `public/` copy. Those are now
translated, in the files that ship.

One string broke a script again — "Today's closed bills" inside a
single-quoted JS string. Same apostrophe trap as the first translation
pass; caught by lint, reworded.

---

## 54. Customer ordering app (PWA), online orders on the POS, colour-coded tablet

Approved: PWA + APK wrapper · cashier confirms · payment at the counter ·
the proposed table colours.

### What was there before

`customer_mobile_app.html` was **79 lines with zero API calls** — a
picture of a phone, not an app. `customer_web_qr.html` the same. Only
the tablet was real.

### The app — `/app.html?b=<slug>`

A working PWA. Menu, search, categories, cart, checkout, and a live
order-status screen (Placed → Confirmed → Ready). Installable from the
browser; each business gets its own colour and logo from its branding.

Built on the **existing** `qr_sessions` / `qr_orders` tables rather than
a second ordering system — which is why the POS needed almost no change
to receive app orders.

What it refuses, and why:

```
no name                -> Please enter your name
short phone            -> Please enter a valid phone number
delivery, no address   -> Please enter the delivery address
empty cart             -> Your cart is empty
price sent as 1        -> ignored; server total 380
4th order in a minute  -> refused (per phone)
```

Prices always come from the database. Whatever the app posts is never
trusted — otherwise anyone could order at their own rate.

`app-order` is exempt from CSRF deliberately: the customer has no
session, so there is nothing for CSRF to protect. Its real defences are
server-side pricing and the per-phone rate limit.

### On the POS

The existing **QR Orders** button is now **Online & QR Orders** and shows
where each one came from:

```
[APP] Takeaway · Bilal · 08:26 · 03211234567
      1x Chicken Biryani = 380
```

App orders carry the customer's name, phone and (for delivery) the
address. Nothing reaches the kitchen until the cashier accepts —
as agreed. The customer sees the change on their own screen within
seconds.

### The tablet

Table colour now carries meaning rather than decoration:

| Colour | Meaning |
|---|---|
| Green | Free |
| Red | Order running |
| **Amber** | **Ready to serve** — the dot pulses |
| Blue | Billed, payment due |

Only a left edge stripe and a dot are coloured; a fully coloured card
tires the eye across a shift. A legend sits above the grid.

`ready` comes from `kitchen_tickets.ticket_status='READY'`. `billed` is
derived from `printer_jobs` — there is no bill-print record for
restaurant orders, so this is the honest signal available rather than an
invented one.

### The APK

Config and instructions in `tools/apk/` (`twa-manifest.json`, README).
I cannot build it here — no Android SDK, no signing key. It is a
one-time `bubblewrap build` on any machine with Node and Java, and after
that **the app updates itself**: screens and menu come from the server,
so a new APK is only needed if the icon, name or start URL changes.

`/app-download.html` checks whether `app-release.apk` actually exists.
Until it does, the page says so and points customers at the web version
instead of offering a broken download.

---

## 55. Password changed on the cloud never reached the branch

An admin reset a business account's password online. The offline node
kept asking for the old one — with the internet up the whole time.

### Cause

`users` **is** in the sync pull list, and `password_hash` is not
excluded. The problem was one missing clause.

`applyUser()` — the path behind **Users & Access → edit** — changed the
password like this:

```php
UPDATE users SET password_hash=?, password_algo=? WHERE id=?
```

No `updated_at`, no `row_version`. Sync selects rows with
`WHERE updated_at > ?` (`Sync::changedRows`), so as far as sync was
concerned that row had never changed. It was never sent down. Not a
network problem, not a timing problem — the change was invisible.

### Why it sometimes appeared to work

The same function updates name, email and phone in a **separate**
statement, and that one does bump `updated_at`. So changing a name
*together with* the password synced fine; changing only the password did
not. That inconsistency is what made it look random.

Other password paths were already correct: `SelfService` (owner's own
password) and `sa-business-reset-admin` (Super Admin → Reset pass) both
bump `row_version` and `updated_at`. Only this one was missing them.

### Fixed

```php
UPDATE users SET password_hash=?, password_algo=?,
                 row_version=row_version+1, updated_at=NOW(6)
 WHERE id=?
```

```
before : updated_at=09:14:17.372397  row_version=1
after  : updated_at=09:14:28.011510  row_version=2
```

The node picks it up on its next sync. Nothing to do by hand — but a
password changed *before* this build still carries its old timestamp, so
for those, change the password once more after deploying.

---

## 56. Offline setup died on "Opcode handlers are unusable due to ASLR"

Setup stopped at step 2 on a customer's machine:

```
php.exe : Fatal Error Opcode handlers are unusable due to ASLR.
Setup did not complete.
```

### Cause — mine

In V96 I added OPcache to the offline PHP for speed (compile once instead
of on every request; it took boot from ~15 ms to ~2 ms). On Windows
machines with **Mandatory ASLR** switched on, php.exe cannot lay out the
opcode handler table and dies immediately — before doing anything.

So a speed optimisation stopped the product from installing at all. That
trade is wrong in every case: the POS runs perfectly well without
OPcache, just a little slower.

### Fixed

Two layers, because either script can be the one that hits it:

1. `resolve_php.ps1` now writes `opcache.file_cache_fallback=1` and
   `opcache.huge_code_pages=0` (the documented mitigation), then **tests
   that php.exe actually starts**. If it does not, it comments out every
   opcache line in php.ini and tests again. Setup carries on and says
   plainly what it did.
2. `install_offline.ps1` watches for the ASLR text in the output and, if
   it appears, disables opcache in every `php.ini` under `runtime\php`
   and retries — in case resolve_php exited before its own check.

Only the opcache lines are commented; timezone, error settings and
realpath cache are left alone:

```
date.timezone=Asia/Karachi
display_errors=Off
;zend_extension=opcache
;opcache.enable=1
;opcache.enable_cli=1
realpath_cache_size=4M
```

If PHP still will not start, the message says so and stops guessing —
that would not be an OPcache problem, and it prints the command to see
the real reason.

Verified: both scripts parse clean under PowerShell 7, and the
replacement was run against a real php.ini — 5 opcache lines disabled,
3 other settings untouched.

**The general lesson:** a performance setting must never be able to stop
installation. Anything added for speed needs a path that gives up on the
speed and keeps the product working.

---

## 57. Cloud data not arriving on the branch — a diagnostic, not a guess

Symptom: on the node the **categories arrived** (all the chips are
there, every one showing 0) but **no menu items** — while the cloud has
43. The dashboard says *Synced, 0 rows waiting*.

Both tables are in the same pull list, so one arriving and the other not
is the whole clue. Two explanations fit, and they need opposite fixes:

- `menu_items` **errored** while being applied — usually a column this
  node's database does not have.
- The cloud **returned nothing** for it — either the node's `site_id`
  does not match the site the items live in, or the watermark is already
  past them (the rows changed before this node existed, so the cloud
  sees nothing "new").

Guessing between those wastes a day. Sync already records the answer:
every table's result goes into `sync_state`.

### `scripts/diagnose_sync.php`

Run **on the branch computer**. Read-only.

```
php scripts/diagnose_sync.php
```

```
=== This computer ===
  role      : local
  site_id   : 3b1f11be-...
  sites here: 1
     3b1f11be  Main Branch  <= configured

=== What sync did, table by table ===
  table              rows here  status  last watermark       last error
  menu_categories    13         OK      2026-09-17 10:00:00
  menu_items         12         ERROR   2026-09-17 10:00:00  Unknown column is_online...
```

It also flags the quiet killer: if the configured `site_id` is not one
of this tenant's sites, site-scoped tables (menu_items among them) come
back empty with **no error at all** — sync reports success and nothing
arrives.

If the watermark is the problem:

```
php scripts/diagnose_sync.php --reset=menu_items
php scripts/sync_worker.php
```

That rewinds the watermark to 1970 so the next pull asks for the whole
table again. It deletes nothing — it only says "send it all again".

---

## 58. Error log in Super Admin, and a faster start on the branch

### Errors had nowhere to go

There was **no error handler at all**. `display_errors=Off` (right — a
customer must not see a stack trace) and `log_errors=On` sent everything
to PHP's own log: container logs on Railway, a file nobody opens on the
branch. So the only way a fault reached you was a customer complaining.

**New: `app_errors` + Super Admin → Error Log.**

Three handlers in `bootstrap.php`: uncaught exceptions, warnings/notices,
and `register_shutdown_function` for fatals — that last one matters most,
because a fatal otherwise vanishes without a trace. Browser JS errors
come in too (`window.onerror` and unhandled promise rejections), which is
the class of fault that hides best: the screen half-renders, the cashier
says "the software is broken", and the reason sits in a console nobody
looks at.

Three deliberate choices:

- **Grouping.** One fault repeating 500 times is **one row with a
  count**, not 500 rows. Without this the table is noise within a day
  and nobody reads it.
- **Request bodies are never stored.** They can hold passwords, card
  numbers, customer phones. Only the action name is kept — enough to
  find the fault, without taking on a second problem.
- **The logger never throws.** If writing a log fails, it fails quietly.
  A logging fault must not take down the work it was watching.

Emails, bcrypt hashes and long digit strings are stripped from the
message before saving:

```
Login failed for ali@shop.com card 4111111111111111
  ->  Login failed for <email> card <number>
```

Node errors are in the sync **push** list, so what breaks on a branch
computer reaches you too. Anything older than 30 days is cleared.

### Local speed: six requests became one

Measured on the node, POS start:

```
settings-get     7.3 ms
shift-current    5.7 ms
pos-boot         8.9 ms
pos-holds        6.4 ms
qr-pending       5.6 ms
licence-status   5.7 ms
TOTAL           39.7 ms
```

Those are **synchronous** calls, and on the branch `php -S` serves
**one request at a time** — so they queue. On a Windows counter PC each
is 25–60 ms, so this is a frozen quarter-second before the POS is
usable, repeated on every delete and refresh (`boot()` runs again).

New `pos-start` returns all of it in one response:

```
pos-start       10.3 ms
requests: 6 -> 1
time:     39.7 ms -> 10.3 ms   (74% less)
```

Each section is wrapped in its own `try` — if one part fails the POS
still starts without it rather than not starting at all. The old
`pos-boot` still works and the POS falls back to it, so a node running
an older server keeps working.

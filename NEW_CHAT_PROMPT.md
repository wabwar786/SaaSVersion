# Project Handoff Prompt — SaaSVersion (Multi-Tenant Restaurant POS SaaS)

> Naye chat mein yeh poora file paste karein, aur saath mein latest ZIP
> (`SaaSVersion_V57_ConsoleAndReset.zip`) upload karein.

---

## Mere baare mein / How to work with me

- Main **Wabwar Software House** (Pakistan) chalata hun — hands-on developer + owner.
- Roman Urdu + English mila kar baat karta hun. Isi tarah jawab dein.
- **Complete, copy-paste ready code** chahiye — diffs ya adhoore snippets nahi.
- Lambi tashreeh ke bajaye seedha code aur natija.
- **Build tasks ke liye: pehle workflow / architecture / design pesh karein** review ke liye.
  Jab main **"Final"** kahun tabhi poora code banayein.
- **Har chhoti tabdeeli par naya ZIP na banayein.** Pehle testing/verification mukammal
  karein, sab pass ho jaye, phir **ek** package dein.
- Industry-standard delivery chahiye: security, performance, scalability, usability,
  reliability har solution mein shamil.

---

## Product

Multi-tenant restaurant POS + all-in-one SaaS platform.
Har business (tenant) ka apna cloud portal, aur chahe to **offline (branch computer)
installation** jo cloud se background mein sync hoti hai.

### Stack
- PHP 8.2 + MySQL/MariaDB, plain HTML/CSS/JS UI (koi framework nahi)
- Railway par deploy (Docker, `php:8.2-apache`)
- Repo: `https://github.com/wabwar786/SaaSVersion.git`
- Live: `https://saasversion-production.up.railway.app`
- Config: `config/cloud.php` (Railway) / `config/local.php` / sealed `config/offline.php`
- Super admin: `super@admin.local / Super@123`
- Test business: Royal Grill (`royal-grill`), owner login `owner / 2cU5Xzyx7M`

### Design system
`public/shared.css` mein sab kuch hai — tokens aur classes.
**Sirf yehi classes use karein**, warna page bay-shakal ho jata hai:
`.app .sidebar .main .header .content` · `.panel .panel-head .panel-body[.flush]` ·
`.table-wrap` + `.table` (+ `.num`, `.t-main`, `.t-sub`) · `.field > span` + input ·
`.form-grid` (+ `.full`) · `.kpis .kpi[.ok|.info|.warn]` · `.btn[.primary|.danger|.sm]` ·
`.tag[.green|.amber|.red]` · `.grid2 .grid3 .split`
(`.card`, `.inp`, `.tablewrap` **maujood nahi hain** — inhen kabhi use na karein.)

### Key files
- `public/api.php` — saare endpoints (bahut bara file)
- `src/Services/Sync.php` — sync engine
- `src/Services/AdminData.php` — backup / factory reset / import
- `src/Services/PageData.php` — posBoot, dashboard, bill numbering
- `src/Services/Auth.php`, `Platform.php`, `PosService.php`, `Pdf.php`
- `approved_ui/restaurant_pos.html` — POS (v33)
- `approved_ui/index.html` — business dashboard
- `approved_ui/super_admin.html` — Platform Console
- `approved_ui/qr.html`, `pair.html`
- `tools/build_offline_bundle.php` — AES-256-GCM sealed offline package
- `tools/sync_suite.py` — 34-test sync suite
- `tools/reset_verify.py` — factory reset verifier
- `scripts/migrate_*.php` — migrations (docker-entrypoint par khud chalti hain)
- `WHATS_UPDATED.md` — har version ka mukammal record

---

## Current state: **V57**

### Sync engine (V40–V55 mein poori tarah dobara likha gaya)
Yeh sab bugs mil kar theek huay — aur inhen dobara na toRein:

1. **23 tables ka koi timestamp column nahi tha** → sync unhen khamoshi se skip karti thi
   (`payments` bhi shamil, isi liye local/cloud sales figures alag the).
   `migrate_sync_columns.php` har syncable table par `updated_at` daalta hai.
2. **Two-way sync**: push 56 = pull 56 tables. Pehle pull sirf 12 master tables ka tha.
3. **Bulk sync**: `sync-push-bulk` / `sync-pull-bulk`. Pehle har table ki alag HTTP request
   thi (100+ requests, 60–90 sec, timeout). Ab **0.4 sec**.
4. **Silent merge khatam**: cloud ab `ON DUPLICATE KEY UPDATE` nahi karta. Wahi `id` ho to
   UPDATE, warna INSERT; koi doosri unique takra jaye to row **reject** + wajah.
   (Pehle do nodes ke bills mil kar ek ho jate the aur raqam badal jati thi.)
5. **Applied count verify**: cloud ne kam rows li to watermark aage nahi barhta.
   3 nakaam koshishon ke baad row quarantine (`sync_activity` status `REJECTED`).
6. **Node-scoped bill numbers**: har offline package ka apna `node_code` (L1, L2…),
   bills `L2-0001`. Purane bina-prefix bills conflict par **khud renumber** ho jate hain.
7. **Schema lookup**: `columns()` ab `DATABASE()` se schema leta hai (pehle config se —
   Railway par naam alag hone se **har table "does not exist"** ban jati thi).
8. **NULL `site_id` rows** ab pull hoti hain (pehle filter unhen chhoR deta tha).
9. **Version handshake**: `sync-ping` build + features deta hai; mismatch par dashboard
   par laal warning. **Check** button mein "Build match" aur "Schema match" steps.
10. **Node heartbeat**: har sync request par node `sync_nodes` mein register hota hai —
    rows bheje baghair bhi cloud par nazar aata hai.
11. Retry: DNS/timeout/5xx par 3 koshishen.

**Sync ka status POS par nahi, dashboard par hai** — "Cloud Synchronization" card:
status pill, last sync, waiting rows, **Sync now / Check / View log**.
Auto-sync 3 jagah se: background loop, dashboard, POS — har **2 minute**.

**Log**: `sync_runs` (har pass, per-table detail) + `sync_activity` (har transfer,
cloud + node dono). 60 din baad khud saaf.

### Platform Console (V56–V57) — `super_admin.html`
Sidebar: Dashboard · Sync Monitor · All Businesses · Create Business ·
**Backup & Reset** · **Import Data** · Audit Log · Health · Account.

- **Backup**: poora business ek JSON file (`FULL` ya `MASTER`), download hoti hai.
- **Factory Reset**: do hifazatein — business ka poora naam type karna, aur
  pichle **1 ghante mein backup** liya gaya ho.
  `TXN` = sirf transactions; `FULL` = sab kuch, **sirf admin login bachta hai**,
  phir branch defaults dobara seed.
  Tables **information_schema se dynamically** nikalti hain (73), hardcoded list nahi.
- **Import**: wahi backup file wapas — **sirf master data** (menu, items, recipes,
  inventory, suppliers, customers, staff/roles, tables, printers).
  Transactional data jaan-boojh kar import nahi hota. Skip/Overwrite option.
- **Audit log**: har super-admin action record.

### Baqi features (pehle se mukammal)
Offline sealed package (AES-256-GCM + HMAC tamper check, portable PHP + MariaDB,
Windows installer), per-tenant branding, feature flags (36 modules), shift management
(per-user per-counter, cash clear gate, handover), QR table ordering, device pairing,
WhatsApp integration, print templates + PDF bills, super admin dashboard.

---

## Testing (yeh chalana zaroori hai)

```bash
# 34-test sync suite — offline node + cloud dono par
python3 tools/sync_suite.py <offline_dir>

# factory reset verifier — har tenant-scoped table ginta hai
python3 tools/reset_verify.py <business-slug>
```

Har release se pehle: PHP lint (sab files) · `php tools/check_pages.php` ·
key pages ka JS `node --check` · sync suite **34/34** · reset verifier CLEAN.

---

## Ahem aadat (mere tajurbe se)

- **Browser mein khud dekh kar verify karein** (Playwright). Kai dafa endpoint theek tha
  magar UI toota hua tha — ek dafa poora JS `<script src="...">` tag ke andar chala gaya
  tha aur kabhi chala hi nahi.
- **Khamosh failure sab se bara dushman hai.** Har error ki asli wajah user tak pahunchni
  chahiye — "rejected by cloud" jaisa be-maani message nahi.
- Cloud aur offline **dono taraf deploy** zaroori hai; version mismatch ho to console
  khud batata hai.

---

## Abhi jo baqi hai (PENDING)

- **FBR integration** (offline version + local PC IP/service; C# code aana hai)
- Offline vendor bundles (`vendor/php.zip`, `vendor/mariadb.zip`) — zero-download install
- Tier-B modules: stock transfer/count UI, void/refund flow, delivery/riders,
  loyalty, accounting, full reports
- ionCube/SourceGuardian (mojooda AES sealing obfuscation hai, na-qabil-e-tor nahi)
- QR ordering ke liye `menu_items.is_online` column
- Offline ke liye multi-worker PHP server (built-in single-threaded hai)
- Sync backlog **drain mode** — ek mahine ka backlog abhi batches mein jata hai;
  drain + bara batch size se minton mein clear ho sakta hai

---

## Pehla kaam

`SaaSVersion_V57_ConsoleAndReset.zip` upload kar raha hun. Isay extract karke
`WHATS_UPDATED.md` parh lein (V40 se V57 tak har fix ka record hai), phir batayein
ke aap ne state samajh li — uske baad main agla kaam dunga.

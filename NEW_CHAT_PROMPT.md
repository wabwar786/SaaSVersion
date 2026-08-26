# Project Handoff Prompt — SaaSVersion (Multi-Tenant Restaurant POS SaaS)

> Naye chat mein yeh poora file paste karein, aur saath latest ZIP
> (`SaaSVersion_V61_MySQL8Fix.zip`) upload karein.

---

## Mere baare mein / How to work with me

- Main **Wabwar Software House** (Pakistan) chalata hun — hands-on developer + owner.
- Roman Urdu + English mila kar baat karta hun. Isi tarah jawab dein.
- **Complete, copy-paste ready code** chahiye — diffs ya adhoore snippets nahi.
- Lambi tashreeh ke bajaye seedha code aur natija.
- **Build tasks ke liye: pehle workflow / architecture / design pesh karein** review
  ke liye. Jab main **"Final"** kahun tabhi poora code banayein.
- **Har chhoti tabdeeli par naya ZIP na banayein.** Pehle testing mukammal karein,
  sab pass ho jaye, phir **ek** package dein.
- Industry-standard delivery: security, performance, scalability, usability,
  reliability har solution mein.

---

## Product

Multi-tenant restaurant POS + all-in-one SaaS. Har business (tenant) ka apna cloud
portal, aur chahe to **offline branch-computer installation** jo cloud se background
mein sync hoti hai.

### Stack
- PHP 8.2 + MySQL/MariaDB, plain HTML/CSS/JS (koi framework nahi)
- Railway par deploy (Docker, `php:8.2-apache`) — **Railway par MySQL 8 hai**
- Repo: `https://github.com/wabwar786/SaaSVersion.git`
- Live: `https://saasversion-production.up.railway.app`
- Config: `config/cloud.php` / `config/local.php` / sealed `config/offline.php`
- Super admin: `super@admin.local / Super@123`
- Test business: Royal Grill (`royal-grill`), owner `owner / 2cU5Xzyx7M`

### MySQL 8 vs MariaDB — yeh mujhe do dafa kaat chuka hai
MySQL 8 `information_schema` ke column names **UPPERCASE** deta hai
(`TABLE_NAME`), MariaDB lowercase. **Har `information_schema` query par explicit
lowercase alias lagayein** — `SELECT table_name AS t`, `SELECT column_name AS c`.
Warna sandbox (MariaDB) par sab chalta hai aur production (MySQL 8) par khamoshi
se tootta hai.

Isi tarah `api.php` ke shuru mein `display_errors=0` hai — ek PHP warning bhi JSON
response tor deti thi aur browser sirf "Request failed" dikhata tha.

### Design system
`public/shared.css` mein sab kuch hai. **Sirf yehi classes use karein:**
`.app .sidebar .main .header .content` · `.panel .panel-head .panel-body[.flush]` ·
`.table-wrap` + `.table` (+ `.num .nw .t-main .t-sub`) · `.field > span` + input ·
`.form-grid` (+ `.full`) · `.kpis .kpi[.ok|.info|.warn]` · `.btn[.primary|.danger|.sm]` ·
`.tag[.green|.amber|.red]` · `.grid2 .grid3 .split`
(`.card`, `.inp`, `.tablewrap` **maujood nahi** — kabhi use na karein.)

### Key files
- `public/api.php` — saare endpoints
- `src/Services/Sync.php` — sync engine
- `src/Services/AdminData.php` — backup / factory reset / purge / delete / import
- `src/Services/AdminConsole.php` — command console (server-side parser)
- `src/Services/PageData.php` · `Auth.php` · `Platform.php` · `PosService.php` · `Pdf.php`
- `approved_ui/restaurant_pos.html` (POS v33) · `index.html` (business dashboard)
  · `super_admin.html` (Platform Console) · `qr.html` · `pair.html`
- `tools/build_offline_bundle.php` — AES-256-GCM sealed offline package
- `tools/sync_suite.py` — 34-test sync suite
- `tools/reset_verify.py` — factory reset verifier
- `scripts/migrate_*.php` — migrations (docker-entrypoint par khud chalti hain)
- `WHATS_UPDATED.md` — V40 se V61 tak har fix ka record

---

## Current state: **V61**

### Sync engine (V40–V55 mein dobara likha gaya) — inhen na toRein
1. **23 tables ka timestamp column nahi tha** → sync unhen khamoshi se skip karti thi
   (`payments` bhi). `migrate_sync_columns.php` har table par `updated_at` daalta hai.
2. **Two-way**: push 56 = pull 56 tables (pehle pull sirf 12 master tables ka tha).
3. **Bulk sync**: `sync-push-bulk` / `sync-pull-bulk` — 100+ requests se **0.4 sec**.
4. **Silent merge khatam**: wahi `id` → UPDATE, warna INSERT; koi unique takra jaye
   to row **reject** + wajah. (Pehle do nodes ke bills mil kar ek ho jate the aur
   raqam badal jati thi.)
5. **Applied count verify** + 3 nakaam koshishon ke baad quarantine.
6. **Node-scoped bill numbers** (`L2-0001`); purane bills conflict par khud renumber.
7. **Schema lookup** `DATABASE()` se (config ke DB naam se nahi).
8. **NULL `site_id` rows** ab pull hoti hain.
9. **Version handshake** — build mismatch par dashboard par laal warning; `Check`
   mein "Build match" aur "Schema match" steps.
10. **Node heartbeat** — rows bheje baghair bhi node cloud par nazar aata hai.
11. Retry: DNS/timeout/5xx par 3 koshishen.

Sync status **dashboard par** hai (POS par nahi): status pill, last sync, waiting
rows, **Sync now / Check / View log**. Auto-sync har 2 minute (background loop +
dashboard + POS).
Log: `sync_runs` + `sync_activity` (cloud aur node dono), 60 din baad khud saaf.

### Platform Console — `super_admin.html`
Sidebar: Dashboard · Sync Monitor · All Businesses · Create Business ·
Backup & Reset · Import Data · **Command Console** · Audit Log · Health · Account.

- **Backup** → JSON file (FULL / MASTER) download, record rakha jata hai.
- **Factory reset** — naam se confirm + 1 ghante ke andar backup lazmi.
  `TXN` = sirf transactions; `FULL` = sab kuch, **sirf admin login bachta hai**,
  phir branch defaults dobara seed. Tables `information_schema` se **dynamically**
  (73), hardcoded list nahi.
- **Delete business** — 73 tenant-scoped tables + sync/admin logs + subscriptions +
  sites + organizations + khud `tenants` row. Kuch nahi bachta.
- **Import** — wahi backup file wapas, **sirf master data** (transactional kabhi nahi).
- **Audit log** — har super-admin action.

### Command Console (V58–V61)
Terminal screen, `>` prompt, Up/Down history, quick-command chips.

    list · info <slug> · users <slug> · footprint <slug> · selftest [slug]
    suspend <slug> · activate <slug>
    backup <slug> [full|master]
    reset <slug> [txn|full] --confirm "<name>"
    purge <slug> <what> [--before YYYY-MM-DD] --confirm "<name>"
        what: transactions|orders|shifts|stock|qr|expenses|logs|sync|all-logs
    delete <slug> --confirm "<name>"
    nodes · sync [slug] · audit [slug] · tables · query SELECT ...
    clear · version · help

**Hifazatein (sab server-side):** confirm ke baghair chalti hi nahi; galat naam par
asli naam ke saath poori command bana kar deta hai; `<slug>` jaise brackets khud
saaf; reset/purge se pehle backup lazmi (logs ke liye nahi); `query` sirf
SELECT/SHOW/DESCRIBE, bina LIMIT par khud `LIMIT 100`; har amal audit mein.
`sa-console` ka jawab **hamesha JSON** (shutdown handler + try/catch), aur client
HTTP status + raw jawab dikhata hai.

**`selftest` sab se pehla diagnostic hai** — tables, permissions, backup status,
data footprint sab check karta hai.

### Baqi features
Offline sealed package (AES-256-GCM + HMAC tamper check, portable PHP + MariaDB,
Windows installer), per-tenant branding, feature flags (36 modules), shift
management (per-user per-counter, cash clear gate, handover), QR table ordering,
device pairing, WhatsApp integration, print templates + PDF bills.

---

## Testing (release se pehle lazmi)

```bash
python3 tools/sync_suite.py <offline_dir>     # 34 tests
python3 tools/reset_verify.py <slug>          # har tenant-scoped table ginta hai
php tools/check_pages.php                     # 44 pages
# + PHP lint sab files, aur key pages ka JS `node --check`
```

**Note:** `reset_verify.py` royal-grill ka data wipe kar deta hai. Us ke baad
offline package se bana node khali hota hai aur sync suite fail karti hai —
pehle backup se restore karein, phir suite chalayein.

---

## Ahem aadat (mehnga seekha)

- **Browser mein khud dekh kar verify karein** (Playwright). Ek dafa poora JS
  `<script src="...">` tag ke andar chala gaya tha aur kabhi chala hi nahi —
  endpoint theek tha magar UI mara hua tha.
- **Khamosh failure sab se bara dushman hai.** Har error ki asli wajah user tak
  pohanchni chahiye; "rejected by cloud" jaisa be-maani message nahi.
- **Cloud aur offline dono taraf deploy** zaroori hai. Ek dafa meri chaar updates
  cloud par pohanchi hi nahi thin aur ghanton usi masle par lage.
- Production MySQL 8, sandbox MariaDB — farq ka khayal rakhein (ooper dekhein).

---

## Abhi jo baqi hai (PENDING)

- **FBR integration** (offline version + local PC IP/service; C# code aana hai)
- Offline vendor bundles (`vendor/php.zip`, `vendor/mariadb.zip`) — zero-download install
- Tier-B modules: stock transfer/count UI, void/refund flow, delivery/riders,
  loyalty, accounting, full reports
- ionCube/SourceGuardian (mojooda AES sealing obfuscation hai, na-qabil-e-tor nahi)
- QR ordering ke liye `menu_items.is_online` column
- Offline ke liye multi-worker PHP server (built-in single-threaded hai)
- Sync backlog **drain mode** — ek mahine ka backlog abhi batches mein jata hai
- Console: `impersonate <slug>` (support ke liye business mein login), broadcast message

---

## Pehla kaam

`SaaSVersion_V61_MySQL8Fix.zip` upload kar raha hun. Extract karke
`WHATS_UPDATED.md` parh lein (V40–V61 ka poora record), phir batayein ke state
samajh li — uske baad main agla kaam dunga.

# Restaurant OS — Local + Cloud (Offline-First) Deployment Guide

Yeh software 2 tarah chalta hai, **same code** se:

| Version | Kahan chalta hai | Database | Config |
|--------|------------------|----------|--------|
| **Local (branch)** | Branch PC / LAN | `aio_local` (local MySQL) | `config/local.php` |
| **Cloud (hosting)** | Aapki hosting / VPS | `aio_cloud` (hosting MySQL) | `config/cloud.example.php` → deploy |

Local branch **internet ke baghair bhi 100% chalta hai** (POS, orders, inventory sab local MySQL par).
Jab internet ho, local data har `interval_minutes` (default **5 min**) baad **cloud par upload** ho jata hai — aur "Sync now" button se turant bhi.

---

## 1) LOCAL version chalana (branch PC)

1. MySQL Server chalu hona chahiye (Windows service), `aio_local` database create hona chahiye
   (credentials `config/local.php` mein — default `root` / `Pakistan_123#`).
2. Bas **`START_RESTAURANT.bat`** double-click karein. Yeh khud:
   - PHP + MySQL check karta hai,
   - master data + sync tables (`ui_records`, `sync_state`) bana deta hai,
   - local server start kar ke browser mein Login khol deta hai.
3. **Login:**  `admin@urbanspoon.local`  /  `Admin@123`

Is halat mein `config/local.php` ka `sync.cloud_api_url` **khaali** hai → app **local-only** chalega
(koi upload nahi, sab kuch device par mehfooz). Bilkul theek hai agar abhi cloud nahi chahiye.

---

## 2) CLOUD version host karna (hosting)

1. Hosting par PHP 8.1+ (extensions: `pdo_mysql`, `mbstring`, `json`; `curl` behtar hai) aur MySQL chahiye.
2. Poora project upload karein. Document root ko **`public/`** par point karein (Apache/Nginx/IIS).
3. Cloud database banayein, e.g. `aio_cloud`, phir schema + sync tables load karein:
   ```
   mysql -u USER -p aio_cloud < docs/02_local_mysql_schema.sql
   mysql -u USER -p aio_cloud < docs/03_seed_restaurant_base.sql
   php scripts/migrate_sync.php          # (AIO_CONFIG cloud config ke saath — neeche dekhein)
   ```
4. `config/cloud.example.php` ko copy karke apni cloud DB details + ek **strong shared token** daalein.
   Deploy ke liye do options:
   - **Aasaan:** is file ko `config/local.php` ke naam se rakh dein (hosting par yehi use hogi), **ya**
   - `AIO_CONFIG` environment variable set karein jo cloud config ka path de
     (e.g. Apache `SetEnv AIO_CONFIG /path/config/cloud.php`). Code khud is file ko utha lega.
5. `app.base_url` ko apne domain par set karein (e.g. `https://app.yourdomain.com`).

Cloud node kabhi kisi ko push nahi karta (`push_tables`/`pull_tables` khaali) — woh sirf branches se
data **receive** karta hai aur central reporting deta hai.

---

## 3) LOCAL ko CLOUD se jodna (sync on karna)

`config/local.php` → `sync` block mein:

```php
'sync' => [
    'enabled'        => true,
    'cloud_api_url'  => 'https://app.yourdomain.com',   // <-- aapka cloud URL
    'token'          => 'WAHI-LONG-RANDOM-TOKEN',       // <-- cloud jaisa hi token
    'interval_minutes' => 5,
    ...
],
```

Bas. Ab `START_RESTAURANT.bat` chalega to:
- Local pehle ki tarah offline chalega, **aur**
- Background mein har 5 min sync loop local changes ko cloud par bhej dega,
- "Offline & Sync" screen par live status + **Sync now** button milega.

> **Token dono taraf same hona zaroori hai.** Yehi security hai (server-to-server).

---

## 4) Sync kaise kaam karta hai (short)

- **Push (local → cloud):** har configured table ki sirf **badli hui rows** (`updated_at` ke baad wali)
  cloud par idempotent upsert hoti hain. Watermark `sync_state` mein save hota hai — dobara wahi row nahi jati.
- **Pull (cloud → local):** master data (menu, suppliers, promotions, printers) head-office se
  neeche aati hai (last-write-wins).
- **Naye admin pages** (suppliers, customers, staff, etc.) apna data `ui_records` table mein
  rakhte hain — yeh bhi cloud ko sync hota hai, aur offline par localStorage fallback rehta hai.
- Internet na ho to sync **skip** ho jata hai, app chalta rehta hai; internet aate hi khud upload.

### Manual test (do machines / do DBs):
```
# cloud side
AIO_CONFIG=config/cloud.php php scripts/migrate_sync.php
# local side
php scripts/sync_worker.php     # ek push+pull pass; JSON summary print karta hai
```

---

## 5) Startup error ("server did not start") — fix

Purana launcher server ko sirf ek HTTP request se check karta tha jo Windows **proxy/antivirus** ki
wajah se fail ho jati thi (server chal raha hota tha phir bhi). Naya `tools/windows_bootstrap.ps1`:
- Server ko **TCP port check** se verify karta hai (proxy-independent),
- readiness timeout **30 second** tak,
- server ki asli output `storage/logs/php-server.log` mein likhta hai,
- fail hone par asli wajah dikhata hai.

Agar phir bhi ruke: `STOP_OLD_RESTAURANT_SERVERS.bat` chala kar dobara `START_RESTAURANT.bat` karein.

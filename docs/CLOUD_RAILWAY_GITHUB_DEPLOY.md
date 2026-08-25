# Cloud (Railway) + GitHub Deploy — Super Admin, Business Create, Offline Link

Yeh online (cloud) version hai jahan **Super User** businesses create karta hai aur har
client ko ek **login link** milta hai. Client online login karta hai, aur wahin se
**offline version download** kar sakta hai jo net aate hi cloud se sync karta hai.

Same code se do node:  **cloud** (`config/cloud.php`, env-based)  aur  **local/offline**
(`config/local.php`).  `app.role` isko farق karta hai. Cloud multi-tenant hai (tenant
login/slug se resolve hota hai), local single-tenant.

---

## A) Code GitHub par

```
cd restaurant_final
git init
git add .
git commit -m "All-in-One Platform: restaurant + SaaS super admin + sync"
git branch -M main
git remote add origin https://github.com/<you>/<repo>.git
git push -u origin main
```
`.gitignore` already logs/sessions/test-config ko chhod deta hai.

## B) Railway par deploy

1. Railway → **New Project → Deploy from GitHub repo** → yeh repo chunein.
   (Repo mein `Dockerfile` + `railway.json` hai — Railway khud Docker se build karega.
   Apache ka docroot `public/` set hai, sab kuch `router.php` se route hota hai.)
2. Usi project mein **New → Database → MySQL** add karein. Railway khud
   `MYSQLHOST / MYSQLPORT / MYSQLUSER / MYSQLPASSWORD / MYSQLDATABASE` env de deta hai —
   `config/cloud.php` inhe automatically parh leta hai.
3. App service ke **Variables** mein add karein:
   - `AIO_CONFIG` = `config/cloud.php`
   - `APP_BASE_URL` = aapka Railway public URL (e.g. `https://xyz.up.railway.app`)
   - `SYNC_TOKEN` = ek lamba random token (offline nodes mein yehi dalna hoga)
   - (optional) `APP_DEBUG=0`
4. Deploy hone par entrypoint **khud schema + migrations chala deta hai**
   (`install_schema`, `migrate_platform`, `migrate_sync`, roles) aur ek **super admin**
   bana deta hai. DB manually chhune ki zaroorat nahi.

## C) Super Admin — business create

1. Kholein: `https://<your-app>/super_admin.html`
2. Login: **super@admin.local / Super@123**  → *(pehli login ke baad change karein — abhi
   change-UI next step hai; filhaal DB ya env se set kar sakte hain).*
3. **Create Restaurant Business** form bharein: business name, owner name/email/phone,
   branch, plan, **amount + payment method + reference**, **start + expiry date**.
4. Create → ek card milega:
   - **Client Login Link**  (e.g. `https://<your-app>/login.html?b=royal-grill`)
   - **Admin Email + one-time Password**  (client ko dena)
   - **Sync Token**  (offline setup ke liye)
   Business list mein bhi har business ka link + expiry + status dikhta hai.

## D) Client — online login + offline download

- Client apne **link** se login karta hai → sirf apna restaurant data dekhta hai
  (server-side tenant isolation; URL sirf slug hai, authorization nahi).
- **Offline version** (branch PC ke liye): client ko offline package (yehi project ka
  local build) chahiye. Uske `config/local.php` mein yeh 4 cheezein set karni hain
  (Super Admin card se milti hain):
  ```php
  'app' => [ 'role'=>'local',
             'tenant_id'=>'<business tenant_id>',
             'site_id'  =>'<business site_id>' , ... ],
  'sync'=> [ 'enabled'=>true,
             'cloud_api_url'=>'https://<your-app>',
             'token'=>'<SYNC_TOKEN>' , ... ],
  ```
  Phir `START_RESTAURANT.bat` — offline chalega, aur net aate hi har 5 min cloud se
  sync karega. (Aage: online screen par "Download Offline Version" button jo yeh config
  automatically stamp karke ZIP de dega — next build step.)

---

## Local offline app (client PC) — recap
- MySQL + `aio_local` (ya bundled) → `START_RESTAURANT.bat` → login → use.
- Debug/visible: `RUN_SERVER_DEBUG.bat`.
- Offline 100% chalta hai; internet aate hi cloud (Railway) se auto-sync.

## Security notes (brief ke mutabiq)
- Cloud MySQL kabhi publicly expose nahi (sirf app service usse connect karti; port 3306 public nahi).
- DB creds sirf server env mein, frontend/JS mein nahi.
- Har request server-side tenant check karti hai; slug se authorization nahi milti.
- Ek business ka user doosre ka data nahi dekh sakta (tenant_id har query mein).

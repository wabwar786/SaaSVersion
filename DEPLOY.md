# DEPLOY — GitHub Desktop se Railway tak

Yeh file batati hai ke naya code live kaise hota hai. Agar kabhi lagay
ke "code diya tha magar site par asar nahi hua", to sab se pehle
neeche wala **check** chalayein.

---

## Samajhne ki ek baat

```
Aapka PC  ──push──>  GitHub  ──khud build──>  Railway
```

Railway ke andar koi folder nahi hai jahan files rakhein. Railway sirf
GitHub repo (`wabwar786/SaaSVersion`, branch `main`) ko dekhta rehta
hai. Wahan naya commit aate hi khud build kar ke chala deta hai.

**Zip kisi ko dena aur zip Railway par jana — do alag cheezein hain.**

---

## Steps (GitHub Desktop)

1. **Clone** (sirf pehli dafa)
   GitHub Desktop → **File → Clone repository → URL**
   `https://github.com/wabwar786/SaaSVersion`
   Folder chunein, misal `C:\Work\SaaSVersion`

2. **Files replace karein**
   Zip extract kar ke saara content us clone folder mein paste karein →
   **Replace all** dabayein.

   ⚠️ `.git` naam ka chhupa hua folder **delete na karein** — wahi
   GitHub se rishta rakhta hai.

3. **Commit**
   GitHub Desktop khud saari tabdeeliyan dikha dega. Neeche summary
   likhein (misal `Retail vertical fixes`) → **Commit to main**.

4. **Push**
   Upar **Push origin** dabayein.

5. Railway 2–3 minute mein khud build kar deta hai.
   Dashboard → service → **Deployments** tab mein "Building" phir
   "Success" nazar aayega.

---

## Deploy hua ya nahi — check

Browser mein kholein (login ki zaroorat nahi):

```
https://<aapka-domain>/api.php?action=build-info
```

Aisa JSON aana chahiye:

```json
{
  "version": "V96 build 2026-09-01",
  "retail_code": true,
  "retail_screens": 41,
  "retail_tables": true,
  "region_column": true,
  "router_fix": true,
  "modules": { "restaurant": 39, "retail": 40 },
  "super_accounts": 1
}
```

| Jawab | Matlab |
|---|---|
| `{"ok":false,"message":"Unknown API action"}` | Naya code live **nahi** hua — push dobara dekhein |
| `router_fix: false` | Purana router — page-not-found wala bug abhi maujood hai |
| `retail_tables: false` | Migration nahi chali |
| `modules.retail: 0` | `seed_industry_modules.php` nahi chali |
| `super_accounts: 0` | Koi super admin account hi nahi — isi liye password kaam nahi karta |

---

## Migrations

Deploy par entrypoint khud chalata hai — kuch karne ki zaroorat nahi.
Haath se chalane hon to Railway shell mein:

```bash
php scripts/migrate_retail.php
php scripts/seed_industry_modules.php
```

Dono idempotent hain — dobara chalane se kuch nahi tootta.

---

## Login ka masla

```bash
php scripts/diagnose_login.php                                  # sab kuch
php scripts/diagnose_login.php super 'Password@123'             # super admin
php scripts/diagnose_login.php <slug> <email> <password>        # ek business
```

Yeh kuch badalta nahi — sirf batata hai ke kaunsa qadam nakaam hua.

Super admin ka password bhool jayen to:
```bash
php scripts/reset_super_admin.php                                    # list
php scripts/reset_super_admin.php --email=you@x.com --password='Pass@123'
```

⚠️ Agar Railway ke **Variables** mein `SUPER_ADMIN_EMAIL` /
`SUPER_ADMIN_PASSWORD` maujood hain, to har deploy par password
**dobara set** ho jata hai. Us soorat mein wahin badlein, ya un
variables ko hata dein.

---

## Pehli dafa deploy karne se pehle

Database ka backup le lein. `seed_industry_modules.php` maujooda
modules ko dobara classify karti hai (COMMON / RESTAURANT / RETAIL).
Test par restaurant ke 39 modules waise ke waise rahe, magar aap ke
asli data par yeh pehli dafa chalegi.

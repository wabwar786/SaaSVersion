# Platform Console — Poori Command List

**Wabwar Software House · SaaSVersion**
Build: V62.3 · 26 Aug 2026

Yeh list `src/Services/AdminConsole.php` se seedha nikali gayi hai.
Har command **server par** chalti hai (browser mein nahi), aur har
khatarnak amal `admin_audit` mein record hota hai.

Console kahan hai: **Platform Console → sidebar → Command Console**

---

## Fehrist

1. [Do baatein jo sab se ziada atkati hain](#do-baatein-jo-sab-se-ziada-atkati-hain)
2. [Padhne wale (mehfooz)](#1-padhne-wale--mehfooz)
3. [Business ki halat](#2-business-ki-halat)
4. [Backup](#3-backup)
5. [Reset — data saaf, business rehta hai](#4-reset--data-saaf-business-rehta-hai)
6. [Purge — chuni hui cheezein](#5-purge--chuni-hui-cheezein)
7. [Delete — business hi khatam](#6-delete--business-hi-khatam)
8. [Resync — node ko cloud ke barabar lana](#7-resync--node-ko-cloud-ke-barabar-lana)
9. [SQL](#8-sql)
10. [Kaam ke nuskhe](#kaam-ke-nuskhe)
11. [Ghalti ke waqt kya karein](#ghalti-ke-waqt-kya-karein)

---

## Do baatein jo sab se ziada atkati hain

**1. `--confirm` mein business ka POORA NAAM chahiye — slug nahi.**

Capital/small letters bilkul wahi jo `list` mein dikhte hain. Server
`tenants.name` se match karta hai. Galat likhein to console khud sahi
command bana kar de deta hai:

```
Run:  delete royal-grill --confirm "Royal Grill"
```

**2. `<slug>` ke brackets mat likhein.**

`<` aur `>` sirf placeholder hain. Console inhen khud saaf kar deta hai
magar likhne ki zaroorat hi nahi.

| Ghalat | Sahi |
|---|---|
| `info <royal-grill>` | `info royal-grill` |
| `delete royal-grill --confirm "royal-grill"` | `delete royal-grill --confirm "Royal Grill"` |

**Keyboard:** Up / Down arrow se purani commands wapas aati hain.

---

## 1. Padhne wale — mehfooz

Yeh kuch nahi badalte. Bila jhijak chalayein.

| Command | Kaam |
|---|---|
| `help` | Saari commands ki list |
| `version` | Build, PHP version, database ka naam |
| `list` · `ls` | Har business: slug, naam, status, expiry, branches |
| `info <slug>` | Ek business ki tafseel — users, orders, sites, subscription |
| `users <slug>` | Us business ke users aur unke roles |
| `permissions <slug>` · `perms <slug>` | Har user ke modules — **DIRECT vs VIA ROLE** — aur upar module fingerprint |
| `footprint <slug>` | Kis table mein kitni rows |
| `selftest [slug]` | **Sab se pehla diagnostic.** Tables, permissions, backup status, data footprint |
| `nodes` | Saare branch computers — last seen, build, status |
| `sync [slug]` | Sync ki halat: kya gaya, kya aaya, kab |
| `tombstones [slug]` · `deletes` | Pending / applied **delete signals** |
| `audit [slug]` | Har super-admin action ka record |
| `tables` | Database ki saari tables + row counts |
| `clear` | Screen saaf |

### `permissions` kab chahiye

Jab **online aur local par modules alag** dikhein (misal local par
"6 Modules", online par "0 Modules"). Yeh batata hai ke modules kis
raste se mil rahe hain — user ko seedhe assign kiye gaye (DIRECT) ya
role ke zariye (VIA ROLE) — aur upar module fingerprint dikhata hai jo
cloud aur node par **ek jaisa hona chahiye**.

### `tombstones` kab chahiye

Jab cloud par data delete/reset ho chuka ho magar branch computer par
purana data abhi bhi nazar aa raha ho. `pending` matlab node ne wo
signal abhi apply nahi kiya.

---

## 2. Business ki halat

```
suspend <slug>          business band — us ke users ka login band
activate <slug>         dobara chalu
```

Suspend hone par us business ki **sync bhi ruk jati hai**.

---

## 3. Backup

```
backup <slug>           FULL (default)
backup <slug> full      sab kuch — master + transactional
backup <slug> master    sirf master data (menu, items, users, settings)
```

JSON file download hoti hai aur record `admin_backups` mein rehta hai.

> **Yaad rakhein:** `reset` aur `purge` isi record ko dekh kar chalte
> hain — pichle **ek ghante** ke andar backup na ho to command chalti
> hi nahi.

---

## 4. Reset — data saaf, business rehta hai

```
reset <slug> txn  --confirm "<poora naam>"      sirf transactions
reset <slug> full --confirm "<poora naam>"      sab kuch
```

| Mode | Kya jata hai | Kya bachta hai |
|---|---|---|
| `txn` | orders, payments, shifts, stock movements, expenses, QR | menu, items, users, roles, settings — sab |
| `full` | sab kuch | **sirf admin login** (roles + branch defaults khud dobara ban jate hain) |

**Backup lazmi hai.** Na ho to:

```
Take a backup first:  backup royal-grill full
```

**V62 se:** reset se pehle har table par **wipe marker** likha jata hai.
Yani branch computer bhi agli sync par apna data khud saaf kar deta
hai. Pehle wo hamesha zinda reh jata tha.

---

## 5. Purge — chuni hui cheezein

```
purge <slug> <what> [--before YYYY-MM-DD] --confirm "<poora naam>"
```

| `<what>` | Kya jata hai |
|---|---|
| `transactions` · `txn` | orders + shifts + stock + qr + expenses — sab ek saath |
| `orders` | bills, order items, payments, refunds, order status history, online orders, KOT, delivery, FBR invoices |
| `shifts` | cashier shifts, cash movements, handovers |
| `stock` | stock transactions + lines, balances, adjustments, movements, batches, count sessions, transfers, GRN + items, purchase orders + items |
| `qr` | QR orders aur sessions |
| `expenses` | expenses + supplier payments |
| `logs` | audit logs, notification queue, printer jobs, background jobs |
| `sync` | sync activity, runs, conflicts, inbox, outbox, cursors |
| `all-logs` | `logs` + `sync` dono |

`--before` lagayein to sirf us tareekh se **purana** data jata hai:

```
purge royal-grill orders --before 2026-01-01 --confirm "Royal Grill"
```

**Backup gate** `logs` aur `sync` par nahi lagta — baqi sab par lagta hai.

---

## 6. Delete — business hi khatam

```
delete <slug> --confirm "<poora naam>"
```

Yeh sab jata hai:

- 73 tenant-scoped tables ka poora data
- sync logs, admin logs, audit
- subscriptions aur payments
- sites aur organizations
- signup requests (owner ki email se)
- khud `tenants` row

**Kuch nahi bachta. Yeh wapas nahi aata.**

Chalane se pehle `backup <slug> full` zaroor le lein — command khud
backup nahi mangti, magar backup ke baghair kuch bhi bahal nahi hoga.

---

## 7. Resync — node ko cloud ke barabar lana

```
resync <slug> transactions --confirm "<poora naam>"
resync <slug> all          --confirm "<poora naam>"
```

**Cloud ka data BILKUL nahi chhoota.** Yeh sirf wipe markers likhta
hai. Agli sync par branch computer apni wahi tables khud saaf kar ke
cloud se dobara bharta hai.

| Mode | Kya reset hota hai node par |
|---|---|
| `transactions` | sirf transactional tables (menu, items, users, settings mehfooz) |
| `all` | master data bhi (users/roles phir bhi mehfooz) |

**Kab chahiye:** cloud par data 0 hai magar branch computer par purana
data abhi bhi nazar aa raha hai — khaas kar jab reset purane build ke
waqt hua ho jab delete channel maujood hi nahi tha.

**Cut-off:** command chalne ke waqt ka timestamp mark hota hai, is liye
us ke **baad** ka naya data kabhi nahi mit'ta.

---

## 8. SQL

```
query SELECT ...
select ...                (seedha bhi chalta hai)
```

Hifazat — sab server-side:

- Sirf `SELECT` · `SHOW` · `DESCRIBE` · `DESC` · `EXPLAIN`
- `INSERT UPDATE DELETE DROP ALTER TRUNCATE CREATE GRANT REPLACE` — **block**
- `LIMIT` na likhein to khud `LIMIT 100` lag jata hai

Misalein:

```
query SELECT email, role, status FROM platform_users
select bill_no, grand_total FROM orders ORDER BY created_at DESC LIMIT 20
query SHOW COLUMNS FROM menu_items
query SELECT COUNT(*) FROM orders WHERE order_status='OPEN'
```

---

## Kaam ke nuskhe

### Khatarnak kaam se pehle ki tarteeb

```
list                             slug aur poora naam
info royal-grill                 kis cheez ko haath laga rahe hain
footprint royal-grill            kitna data jayega
backup royal-grill full          backup (reset/purge ke liye lazmi)
selftest royal-grill             sab theek hai?
reset royal-grill txn --confirm "Royal Grill"
```

### "Local par data hai, live par nahi" (ya ulta)

```
selftest royal-grill
nodes                            node connect ho raha hai?
sync royal-grill                 aakhri sync kab hui, kya gaya
tombstones royal-grill           delete signals pending to nahi?
resync royal-grill transactions --confirm "Royal Grill"
```
Phir branch computer par: dashboard → **Sync now**

### "User ke modules online 0 dikha rahe hain"

```
permissions royal-grill
```
Fingerprint note karein. Branch computer par dashboard → **Check** →
**"Module IDs match"** sabz hona chahiye. Laal ho to node par
`php scripts/migrate_module_ids.php` chalayein, **phir** sync karein.

### Business ki mayaad barhani hai

Console mein nahi — Platform Console → **All Businesses** → us business
par **Renew**.

---

## Ghalti ke waqt kya karein

| Message | Matlab | Hal |
|---|---|---|
| `Unknown command: xyz` | Command maujood nahi | `help` |
| `Confirmation does not match. Expected: ...` | `--confirm` ka naam ghalat | Jo naam dikhaya gaya, bilkul wahi likhein |
| `Take a backup first: backup <slug> full` | Ek ghante ke andar backup nahi hai | Pehle backup lein |
| `No business with slug 'x'` | Slug ghalat | `list` |
| `Only SELECT / SHOW / DESCRIBE are allowed here.` | Write query | Console read-only hai |
| `Write statements are blocked in the console.` | Query mein UPDATE/DELETE | Jaan-boojh kar block hai |
| `Super admin login required` | Session khatam | Dobara login |
| `Security token expire ho gaya tha` | CSRF token purana | Khud dobara koshish karta hai; na chale to page refresh |
| `Server returned HTTP 500 (not JSON)` | Server par PHP error | Railway deploy log dekhein |

---

## Agar Platform Console ka login hi na chale

Do raste — dono ko shell ki zaroorat nahi:

**A. Railway Variables**
Service → Variables mein `SUPER_ADMIN_EMAIL` aur `SUPER_ADMIN_PASSWORD`
daal kar redeploy. Boot log mein tasdeeq. **Uske baad dono variables
hata dein.**

**B. Seedha database**
```sql
SELECT id, email, role, status FROM platform_users;
```
Ya account hi uda dein — agli login koshish par default
(`super@admin.local` / `Super@123`) khud dobara ban jata hai:
```sql
DELETE FROM platform_users WHERE role = 'SUPER';
```

**C. Shell mil jaye to** (`railway ssh`, `railway run` nahi):
```bash
php scripts/reset_super_admin.php
php scripts/reset_super_admin.php --email="you@x.com" --password="NayaPass@123"
```

---

*Wabwar Software House · SaaSVersion V62.3*

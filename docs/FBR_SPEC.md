# FBR / Provincial Digital Invoicing — Spec

**Status:** Phase 4 — abhi banaya NahI gaya. Yeh design record hai.

> **Ahem:** yeh spec **hamare** system ki terms mein likha hai — hamari
> tables, hamare endpoints, hamare labels. Reference ke liye ek purane C#
> POS ka code dekha gaya tha, magar us ke control names, column names,
> screen labels aur workflow **kuch bhi hamare software mein nahi aayega**.
> Us se sirf ek cheez li gayi hai: **FBR ke apne API ka contract** (field
> names jaise `POSID`, `USIN`, `PCTCode`) — wo FBR ka tay kiya hua hai,
> usay badla nahi ja sakta.

---

## 1. Buniyadi haqeeqat: FBR sirf OFFLINE version par

FBR ka fiscal service usi computer par install hota hai jahan POS chal raha
hai, aur uska endpoint **localhost** par hota hai. Cloud (Railway) se us
tak pohancha hi nahi ja sakta.

Yehi baat printers ki hai — wo bhi LAN par local hain.

**Nateeja:**

- Cloud portal par FBR ka page **dikhega hi nahi**
- Offline installation par dikhega, aur wahan bhi tabhi jab enable ho
- `cfg('app.role') === 'cloud'` par saare fiscal endpoints `fail()` karenge

---

## 2. Hamare system mein kahan lagega

Mojooda flow:

```
POS  →  api.php  case 'pos-finalize'  →  PosService::finalize()
     →  bill_pdf / print
```

Naya flow:

```
POS  →  pos-finalize  →  PosService::finalize()
     →  FiscalService::submit($orderId)      ← naya
     →  bill_pdf / print   (invoice no + QR bill par)
```

Yani fiscal submit **bill close hone ke baad, print se pehle**.

Naya: `src/Services/FiscalService.php`

---

## 3. Hamare data ki tabdeeliyan

| Kahan | Kya |
|---|---|
| `orders` | `fiscal_invoice_no VARCHAR(60) NULL`, `fiscal_status VARCHAR(20)` (`NONE` / `PENDING` / `SENT` / `FAILED`) |
| `menu_items` | `pct_code VARCHAR(12) NULL` — per-item PCT, default settings se |
| `fiscal_invoices` | **schema mein pehle se maujood** — submission log yahan |
| `site_settings` | group `fiscal` — neeche wali settings |

Koi nayi "sale" table nahi. Hum apni `orders` / `order_items` par hi kaam
karenge.

---

## 4. FBR ko bheja jane wala payload

Yeh field names **FBR ke hain** — inhen badla nahi ja sakta.

### Header

| FBR field | Hamare paas se |
|---|---|
| `POSID` | `site_settings: fiscal.pos_id` |
| `USIN` | `orders.bill_no` |
| `DateTime` | `orders.closed_at` |
| `BuyerNTN` · `BuyerCNIC` | `customers` (khali ho sakta hai) |
| `BuyerName` · `BuyerPhoneNumber` | `customers.full_name` / `.phone` |
| `TotalBillAmount` | Σ line_total |
| `TotalQuantity` | Σ qty |
| `TotalSaleValue` | Σ line_sale |
| `TotalTaxCharged` | Σ line_tax |
| `Discount` | `orders.discount_amount` |
| `FurtherTax` | 0 |
| `PaymentMode` | payment method se map (neeche) |
| `InvoiceType` | 1 (normal) / 3 (refund) |
| `Items[]` | `order_items` se |

### Items

| FBR field | Hamare paas se |
|---|---|
| `ItemCode` | `menu_items.id` (ya SKU) |
| `ItemName` | `order_items.item_name_snapshot` |
| `Quantity` | `order_items.qty` |
| `PCTCode` | `menu_items.pct_code` → warna `fiscal.default_pct` |
| `TaxRate` | payment mode ke hisaab se rate |
| `SaleValue` · `TotalAmount` · `TaxCharged` | neeche wale formule se |
| `Discount` · `FurtherTax` | line discount / 0 |

### PaymentMode mapping

Hamari `payment_methods.method_type` se:

| Hamara | FBR |
|---|---|
| `CASH`, `COD` | 1 |
| `CARD` | 2 |
| `BANK`, `WALLET` | 1 |

---

## 5. Tax ka hisaab — hamara apna

Do usool:

1. **Andar ka hisaab poori precision par.** Rounding sirf aakhir mein, sirf
   bhejte/chhapte waqt (2 decimal).
2. **Header lines ke jama se banta hai** — alag se dobara calculate nahi
   hota. Har line ka tax alag nikaal kar jama kiya jata hai.

Yeh doosra usool ahem hai: agar header alag se calculate ho aur lines alag
se, to har line ki rounding ka farq jama ho kar total mismatch bana deta
hai, aur FBR aisi invoice reject kar deta hai.

### Tax-exclusive (rate price ke upar)

```
line_sale  = unit_price × qty − line_discount
line_tax   = line_sale × rate / 100
line_total = line_sale + line_tax
```

### Tax-inclusive (rate price ke andar shamil)

```
line_total = unit_price × qty − line_discount
line_sale  = line_total × 100 / (100 + rate)
line_tax   = line_total − line_sale
```

### Header

```
TotalSaleValue  = Σ line_sale
TotalTaxCharged = Σ line_tax
TotalBillAmount = Σ line_total
TotalQuantity   = Σ qty
```

### Rate kahan se

`pos_settings` se — **wahi do rates jo V63 se Settings page par hain**:

- payment mode CASH → `tax_cash`
- payment mode CARD / wallet / bank → `tax_card`

Alag fiscal tax rate nahi banayenge. Ek hi jagah.

### Service charge

`orders.service_charge` bill par lagta hai magar **FBR ko nahi jata** —
sales tax ka hissa nahi.

### POS fee

FBR apni POS fee (Re. 1 per invoice) ka taqaza karta hai. Settings mein
on/off, default off. On ho to bill par alag line: `FBR POS Fee`.

---

## 6. Response aur nakami

Response ka **asli JSON parse** hoga (invoice number ke field se). Fixed
offsets ya substring hacks nahi.

Nakami par:

- **Bill nahi rukega** — customer counter par khara hai
- Bill par saaf: `FBR: PENDING`
- `fiscal_invoices` mein row `PENDING` ke saath, `orders.fiscal_status = PENDING`
- Background retry (3 koshishen, barhta hua wafqa)
- Dashboard par pending count, aur FBR page par "Retry now"

**Khamosh nakami kabhi nahi.** Nakam bill ka pata usi waqt chalna chahiye,
mahine ke aakhir mein nahi.

---

## 7. Provider

Har soobe ka apna nizam hai (FBR federal, KPRA, PRA, SRB, BRA). Design
**provider-based** hoga:

```
fiscal.provider = NONE | FBR | KPRA | ...
```

`FiscalService` mein har provider ka apna adapter. Abhi FBR aur KPRA;
baqi baad mein bina core chhue add ho sakenge.

---

## 8. FBR page (sirf offline) — hamare apne labels

`approved_ui/fbr.html` — mojooda khali shell ki jagah:

**FBR / Digital Invoice**

| Label | |
|---|---|
| Provider | None / FBR / KPRA |
| Fiscal service URL | localhost ka address |
| POS ID | |
| NTN | |
| Access key | |
| Prices include tax | Yes / No |
| Default PCT code | |
| FBR POS fee (Re. 1) | On / Off |

Neeche: **Connection test**, aur **Pending invoices** ki list (bill no,
waqt, wajah, Retry).

Cloud par yeh page kholne par sirf ek note: *"FBR sirf offline version par
chalta hai (local fiscal service aur local printers ke liye)."*

---

## 9. Testing

- Tax-exclusive aur tax-inclusive dono par: Σ lines == header (paisa exact)
- Service charge FBR total mein **nahi**
- Fiscal service band ho → bill phir bhi chhape, `PENDING` ke saath
- Retry se `SENT`, invoice no aur QR bill par
- Cloud par fiscal endpoints 403

---

*Phase 4. Isse pehle Phase 2 (roles + printers) aur Phase 3 (reports).*

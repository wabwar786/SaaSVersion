# FBR / KPRA Digital Invoicing — Spec

**Source:** `restaurant_sale.cs` (mojooda C# POS jo customer chala raha hai)
**Status:** Phase 4 — abhi banaya NahI gaya. Yeh sirf mehfooz kiya hua record hai.

---

## 1. Buniyadi haqeeqat: FBR sirf OFFLINE version par chal sakta hai

C# code mein endpoint yeh hai:

```
http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel
```

**`localhost`.** FBR ka fiscal service usi computer par install hota hai jahan
POS chal raha hai. Cloud (Railway) se is tak pohancha hi nahi ja sakta.

Isi tarah printers bhi local hain (`arx.PrintOptions.PrinterName = "printer1"`).

**Nateeja:** FBR page cloud portal par **dikhega hi nahi**. Sirf offline
installation par, aur wahan bhi tabhi jab customer ne enable kiya ho.

---

## 2. Kab chalta hai

`btnsave_Click` mein — is tarteeb se:

1. Bill database mein save
2. **FBR ko POST** → invoice number wapas
3. Invoice number `stockout.bilty_no` mein save
4. Usi number se **QR code** banta hai
5. Bill print — QR bill par

Yani FBR call **final print se pehle** hoti hai, aur uska number bill par
chhapta hai. (Aap ne bhi yehi kaha tha.)

---

## 3. Request payload

### Invoice (header)

| Field | Note |
|---|---|
| `POSID` | int — FBR se mila hua POS registration id |
| `USIN` | bill number |
| `DateTime` | ab ka waqt |
| `BuyerNTN` · `BuyerCNIC` | optional |
| `BuyerName` · `BuyerPhoneNumber` | customer |
| `TotalBillAmount` | bill ka total |
| `TotalQuantity` | saari qty ka jama |
| `TotalSaleValue` | tax se pehle |
| `TotalTaxCharged` | tax ki raqam |
| `Discount` | |
| `FurtherTax` | 0 |
| `PaymentMode` | CASH → 1, CARD → 2, baqi → 1 |
| `InvoiceType` | 1 |
| `Items[]` | neeche |

### InvoiceItems

`ItemCode` · `ItemName` · `Quantity` · `PCTCode` · `TaxRate` ·
`SaleValue` · `TotalAmount` · `TaxCharged` · `Discount` · `FurtherTax` ·
`InvoiceType`

`PCTCode` C# mein `"98016000"` hardcoded hai. Hamare paas yeh
**per-item configurable** hona chahiye (`menu_items.pct_code`), default
`98016000`.

---

## 4. Tax ka hisaab

Customer ne kaha: *"formula tum khud apni taraf se lagana."*

Purane code ka masla: `Math.Round(x, 1)` aur `Math.Round(x)` mila kar use
hota hai, is liye har line par ek-do rupay ka farq aa jata hai aur bill ka
total items ke jama se match nahi karta. FBR aisa mismatch reject kar deta
hai.

**Hamara tareeqa — do usool:**

1. **Andar ka hisaab poori precision par** (paisa level), rounding sirf
   aakhir mein aur sirf display/print ke liye.
2. **Har line ka tax alag nikaal kar jama** — header ka total us jama ke
   barabar rakha jaye, alag se dobara calculate na ho. Isi se mismatch
   khatam hota hai.

### Tax-exclusive (rate price ke upar lagta hai)

```
line_sale  = unit_price × qty − line_discount
line_tax   = line_sale × rate / 100
line_total = line_sale + line_tax
```

### Tax-inclusive (rate price ke andar shamil hai)

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

Har cheez 2 decimal par round hoti hai **sirf bhejte waqt**, aur header
lines ke jama se banta hai — alag se dobara nahi ginta.

### Kaun sa rate?

`tax_cash` ya `tax_card` — payment mode ke hisaab se (yeh V63 se Settings
page par maujood hain). Purane C# mein bhi `main.credit_card_tax` alag tha,
yani yeh concept customer ke system mein pehle se hai.

### Service charge

C# mein service charge bill par lagta hai magar FBR ko **nahi** jata
(`sc` alag column hai). Hum bhi yehi rakhenge — service charge sales tax
ka hissa nahi.

### POS fee

C# mein: `taxrate > 1 && kpra == ""` → bill mein **Re. 1** FBR POS fee.
Yeh FBR ka apna rule hai; rakhenge, magar Settings se on/off ho sakega.

---

## 5. Response — purane code ka bug

```csharp
string reMfist21 = HtmlResult.Substring(18, 30);
FBR_String = reMfist21.Substring(0, reMfist21.IndexOf('"')).Trim();
```

Yeh **fixed byte offsets** par chal raha hai. Response format zara sa badla
(ek space, ek naya field) to ya galat invoice number aayega ya
`ArgumentOutOfRangeException`. Aur poora block `catch (Exception ex) { }`
mein hai — yani **bill khamoshi se bina FBR number ke chhap jayega**.

**Hamara tareeqa:**
- Asli JSON parse (`InvoiceNumber` / `FBRInvoiceNumber` field name se)
- Fail hone par **bill nahi rukega** (customer khara hai), magar:
  - bill par saaf likha aayega `FBR: PENDING`
  - order `fiscal_invoices` mein `PENDING` status ke saath queue hoga
  - background retry (3 koshishen)
  - dashboard par pending count nazar aayega
- Khamosh nakami kabhi nahi.

---

## 6. KPRA (Khyber Pakhtunkhwa) — alag rasta

FBR nahi, simple GET:

```
http://kpra.gov.pk/rims/integration/?ntn=<NTN>&key=<KEY>
   &invoice_no=<bill>&amount=<base>&sts=<tax>&date=<Y-m-d H:i:s>
```

`amount` = tax se pehle, `sts` = tax ki raqam. Response ka koi invoice
number nahi — sirf submission.

Province-wise aur bhi hain (PRA Punjab, SRB Sindh, BRA Balochistan), is
liye design **provider-based** hoga: `fiscal_provider` = `FBR` | `KPRA` |
`NONE`.

---

## 7. Settings jo chahiye hongi (offline-only page)

| Setting | Misal |
|---|---|
| Provider | FBR / KPRA / None |
| Service URL | `http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel` |
| POS ID | FBR se mila hua |
| NTN | |
| Key | KPRA ke liye |
| Price includes tax | Yes / No |
| Default PCT code | `98016000` |
| POS fee (Re. 1) | On / Off |

---

## 8. Tables jo banengi

- `fiscal_invoices` — schema mein **pehle se maujood** hai
- `menu_items.pct_code` — naya column
- `orders.fiscal_invoice_no` + `fiscal_status` — naye columns

---

*Phase 4. Isse pehle Phase 2 (roles + printers) aur Phase 3 (reports).*

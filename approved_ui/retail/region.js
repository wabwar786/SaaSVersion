/* ============================================================
   region.js — Region profile (PK / UK / US)
   Tenant ka region_profile poore UI ka behaviour decide karta hai:
   currency, price mode (tax inclusive vs exclusive), tax driver,
   barcode standard, weight unit aur date format.

   DATA CONTRACT — tenants.region_profile  ENUM('PK','UK','US')
   ============================================================ */
(function () {
  var PROFILES = {
    PK: {
      code: 'PK', label: 'Pakistan', flag: '🇵🇰',
      currency: 'PKR', symbol: 'Rs', locale: 'en-PK', decimals: 0,
      price_mode: 'INCLUSIVE',            // shelf price mein tax shamil
      tax_driver: 'PK_FBR',
      tax_label: 'Sales Tax', default_tax: 17,
      tax_rates: [{ name: 'Standard 17%', rate: 17 }, { name: 'Reduced 15%', rate: 15 }, { name: 'Zero rated', rate: 0 }, { name: 'Exempt', rate: 0 }],
      barcode: 'EAN13', weight_unit: 'kg', scale_prefix: '20',
      credit_label: 'Khata / Udhaar',
      date_fmt: 'DD MMM YYYY',
      fiscal_note: 'FBR digital invoice localhost service se — offline PC par.'
    },
    UK: {
      code: 'UK', label: 'United Kingdom', flag: '🇬🇧',
      currency: 'GBP', symbol: '£', locale: 'en-GB', decimals: 2,
      price_mode: 'INCLUSIVE',
      tax_driver: 'UK_VAT',
      tax_label: 'VAT', default_tax: 20,
      tax_rates: [{ name: 'Standard 20%', rate: 20 }, { name: 'Reduced 5%', rate: 5 }, { name: 'Zero rated 0%', rate: 0 }, { name: 'Exempt', rate: 0 }],
      barcode: 'EAN13', weight_unit: 'kg', scale_prefix: '20',
      credit_label: 'Account Customer',
      date_fmt: 'DD/MM/YYYY',
      fiscal_note: 'VAT records MTD-ready format mein rakhe jate hain.'
    },
    US: {
      code: 'US', label: 'United States', flag: '🇺🇸',
      currency: 'USD', symbol: '$', locale: 'en-US', decimals: 2,
      price_mode: 'EXCLUSIVE',            // tax checkout par add hoti hai
      tax_driver: 'US_SALESTAX',
      tax_label: 'Sales Tax', default_tax: 8.25,
      tax_rates: [{ name: 'State 6.25%', rate: 6.25 }, { name: 'State + County 8.25%', rate: 8.25 }, { name: 'Food 0%', rate: 0 }, { name: 'Exempt', rate: 0 }],
      barcode: 'UPCA', weight_unit: 'lb', scale_prefix: '2',
      credit_label: 'Account Customer',
      date_fmt: 'MM/DD/YYYY',
      fiscal_note: 'Sales tax rate manually set — state/county wise rate table.'
    }
  };

  var KEY = 'retail_region_v1';

  function current() {
    var c = 'PK';
    try { c = localStorage.getItem(KEY) || 'PK'; } catch (e) { }
    return PROFILES[c] || PROFILES.PK;
  }
  function set(code) {
    if (!PROFILES[code]) return;
    _fmtFor = null;
    try { localStorage.setItem(KEY, code); } catch (e) { }
  }

  /* SPEED: Intl.NumberFormat har call par banana mehnga hai. POS ek bill
     mein sainkron baar money() chalata hai — is liye formatter cache. */
  var _fmt = null, _fmtPlain = null, _fmtNum = null, _fmtFor = null;
  function fmts() {
    var p = current();
    if (_fmtFor !== p.code) {
      _fmt = new Intl.NumberFormat(p.locale, { minimumFractionDigits: p.decimals, maximumFractionDigits: p.decimals });
      _fmtPlain = _fmt;
      _fmtNum = new Intl.NumberFormat(p.locale);
      _fmtFor = p.code;
    }
    return p;
  }
  function money(n) { var p = fmts(); return p.symbol + ' ' + _fmt.format(Number(n) || 0); }
  function moneyPlain(n) { fmts(); return _fmtPlain.format(Number(n) || 0); }
  function num(n) { fmts(); return _fmtNum.format(Number(n) || 0); }
  function qty(n) {
    var v = Number(n) || 0;
    if (v % 1 === 0) { fmts(); return _fmtNum.format(v); }
    return v.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
  }
  function weightUnit() { return current().weight_unit; }
  function isExclusive() { return current().price_mode === 'EXCLUSIVE'; }

  /* Shelf price se tax nikalna — price mode ke hisaab se.
     INCLUSIVE : tax price ke andar hai   -> net = gross / (1+r)
     EXCLUSIVE : tax price ke upar lagti  -> tax = net * r          */
  function taxSplit(lineTotal, ratePct) {
    var r = Number(ratePct || 0) / 100;
    if (isExclusive()) {
      var tax = lineTotal * r;
      return { net: lineTotal, tax: tax, gross: lineTotal + tax };
    }
    var net = lineTotal / (1 + r);
    return { net: net, tax: lineTotal - net, gross: lineTotal };
  }

  /* Scale label barcode parse.
     Format: <prefix><PLU 5><value 5><check 1>
     value = weight (grams/units*1000) ya price — item par depend karta hai. */
  function parseScaleBarcode(code) {
    var p = current();
    code = String(code || '').trim();
    if (code.indexOf(p.scale_prefix) !== 0) return null;
    if (code.length < 12) return null;
    var plu = code.substr(p.scale_prefix.length, 5);
    var raw = code.substr(p.scale_prefix.length + 5, 5);
    return { plu: plu, value: Number(raw) / 1000 };
  }

  window.Region = {
    PROFILES: PROFILES, current: current, set: set,
    money: money, moneyPlain: moneyPlain, num: num, qty: qty,
    weightUnit: weightUnit, isExclusive: isExclusive,
    taxSplit: taxSplit, parseScaleBarcode: parseScaleBarcode
  };
})();

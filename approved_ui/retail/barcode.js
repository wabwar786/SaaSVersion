/* ============================================================
   barcode.js — asli, scan hone wala barcode.

   Pehle label par CSS ki dhaariyan thin — dekhne mein barcode lagti
   thin magar scanner unhein parh hi nahi sakta tha. Ab yahan asli
   encoding hai aur SVG banti hai:

     EAN-13   — 13 hindson wala (Pakistan/UK ka aam retail barcode)
     UPC-A    — 12 hindse (USA); EAN-13 mein 0 laga kar chhapta hai
     Code128B — kuch bhi (SKU, PLU, harf-o-adad) — jab EAN na ho

   Koi library nahi, koi CDN nahi — offline PC par internet nahi hota.
   ============================================================ */
(function () {

  /* ---------- EAN-13 ---------- */
  var L = ['0001101','0011001','0010011','0111101','0100011',
           '0110001','0101111','0111011','0110111','0001011'];
  var G = ['0100111','0110011','0011011','0100001','0011101',
           '0111001','0000101','0010001','0001001','0010111'];
  var R = ['1110010','1100110','1101100','1000010','1011100',
           '1001110','1010000','1000100','1001000','1110100'];
  var PARITY = ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG',
                'LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'];

  function ean13Check(d12) {
    var s = 0;
    for (var i = 0; i < 12; i++) s += (+d12[i]) * (i % 2 === 0 ? 1 : 3);
    return (10 - (s % 10)) % 10;
  }

  function encodeEAN13(code) {
    code = String(code).replace(/\D/g, '');
    /* 12 hindse = UPC-A (check digit us mein pehle se hai). EAN-13 banane
       ke liye aage 0 lagta hai — check digit dobara NAHI joda jata,
       warna barcode ghalat ban jata hai aur scanner doosra number parhta hai. */
    if (code.length === 12) code = '0' + code;
    if (code.length !== 13) return null;
    /* Check digit ghalat ho to bhi chhap dete hain — purana stock aksar
       aisa hota hai, aur cashier ko rukna nahi chahiye. */
    var first = +code[0], parity = PARITY[first], bits = '101';
    for (var i = 1; i <= 6; i++) bits += (parity[i - 1] === 'L' ? L : G)[+code[i]];
    bits += '01010';
    for (var j = 7; j <= 12; j++) bits += R[+code[j]];
    bits += '101';
    return { bits: bits, text: code, type: 'EAN-13', guards: true };
  }

  /* ---------- Code 128 (B) ---------- */
  var C128 = [
    '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
    '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
    '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
    '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
    '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
    '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
    '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
    '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
    '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
    '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
    '114131','311141','411131','211412','211214','211232','2331112'
  ];

  function encodeCode128(text) {
    text = String(text);
    var vals = [104];                       // Start B
    for (var i = 0; i < text.length; i++) {
      var c = text.charCodeAt(i);
      if (c < 32 || c > 126) c = 63;        // '?' — na-qabil-e-encode harf
      vals.push(c - 32);
    }
    var sum = 104;
    for (var k = 1; k < vals.length; k++) sum += vals[k] * k;
    vals.push(sum % 103);
    vals.push(106);                         // Stop

    var bits = '';
    for (var v = 0; v < vals.length; v++) {
      var w = C128[vals[v]], on = true;
      for (var b = 0; b < w.length; b++) {
        bits += (on ? '1' : '0').repeat(+w[b]);
        on = !on;
      }
    }
    return { bits: bits, text: text, type: 'CODE128', guards: false };
  }

  /* ---------- code se sahi symbology chunna ---------- */
  function encode(code, prefer) {
    code = String(code == null ? '' : code).trim();
    if (!code) return null;
    var digits = /^\d+$/.test(code);
    if (prefer === 'CODE128') return encodeCode128(code);
    if (digits && (code.length === 13 || code.length === 12)) return encodeEAN13(code);
    if (digits && code.length === 8) return encodeCode128(code);   // EAN-8 abhi nahi
    return encodeCode128(code);
  }

  /**
   * SVG banao.
   * @param opts.height  bars ki oonchai px mein
   * @param opts.showText hindse neeche chhapein
   */
  function svg(code, opts) {
    opts = opts || {};
    var enc = encode(code, opts.type);
    if (!enc) return '';
    var bits = enc.bits,
        n = bits.length,
        h = opts.height || 40,
        fs = opts.fontSize || 9,
        textH = opts.showText === false ? 0 : fs + 2,
        /* EAN mein guard bars thodi lambi hoti hain — scanner isi se
           start/stop pehchanta hai aur nazar bhi durust lagti hai. */
        guardIx = enc.guards ? [0,1,2, 45,46,47,48,49, n-3,n-2,n-1] : [];

    var out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + n + ' ' + (h + textH) +
              '" preserveAspectRatio="none" style="width:100%;height:' + (h + textH) + 'px;display:block">' +
              '<rect width="' + n + '" height="' + (h + textH) + '" fill="#fff"/>';

    var i = 0;
    while (i < n) {
      if (bits[i] === '1') {
        var start = i;
        while (i < n && bits[i] === '1') i++;
        var isGuard = enc.guards && guardIx.indexOf(start) > -1;
        out += '<rect x="' + start + '" y="0" width="' + (i - start) +
               '" height="' + (h + (isGuard ? textH * 0.55 : 0)) + '" fill="#000"/>';
      } else i++;
    }

    if (textH) {
      out += '<text x="' + (n / 2) + '" y="' + (h + textH - 1) +
             '" font-family="monospace" font-size="' + fs +
             '" text-anchor="middle" fill="#000" ' +
             'textLength="' + (n * 0.6) + '" lengthAdjust="spacingAndGlyphs">' +
             enc.text + '</text>';
    }
    return out + '</svg>';
  }

  window.Barcode = { encode: encode, svg: svg, ean13Check: ean13Check };
})();

/* ============================================================
   doc_config.js — paanch document screens ki config.   (V154)

   Engine `doc.js` hai. Yahan sirf wo cheezein likhi hain jo in paanchon
   mein waqai alag hain: kis party ka naam, kaun si qeemat (kharid ki ya
   bikri ki), batch chahiye ya nahi, aur post ka button kya kahega.

   Posting ka hisaab yahan BILKUL NAHI hai — wo server par
   `RetailDocs::post()` mein ek hi jagah hai. Agar wo hisaab yahan bhi
   hota to screen aur server kabhi na kabhi alag ho jate; V153 ke teeno
   bug isi shakal ke the.
   ============================================================ */
(function () {
  window.DOC_CONFIGS = {

    /* ---------------- Goods Receipt ---------------- */
    grn: {
      type: 'GRN', title: 'Goods Receipt (GRN)',
      addLabel: '+ New receipt', postLabel: 'Receive',
      listTitle: 'Goods receipts', listSub: 'Supplier se jo maal aaya — post karte hi stock barh jata hai',
      kpiCount: 'Receipts', kpiValue: 'Received value',
      party: 'supplier', partyLabel: 'Supplier', partyRequired: false,
      refLabel: 'Supplier bill no.', refHint: 'jo parchi maal ke saath aayi',
      rateLabel: 'Cost', rateFrom: 'cost', batch: true, emptyIcon: '⇩',
      emptyText: 'Jab maal aaye, yahan se receive karein.',
      note: '<b>GRN post karte hi teen cheezein hoti hain:</b> stock barhta hai, ' +
            'cost ka <b>weighted average</b> naye bhao se theek hota hai, aur supplier ka ' +
            'payable barh jata hai. Batch/expiry likhein to Batch &amp; Expiry screen par bhi aa jayega. ' +
            'Post hone ke baad GRN badalta nahi — ghalti ho to ulta document post karein.'
    },

    /* ---------------- Purchase Return ---------------- */
    preturn: {
      type: 'PRET', title: 'Purchase Return',
      addLabel: '+ New return', postLabel: 'Return',
      listTitle: 'Purchase returns', listSub: 'Kharab ya ghalat maal supplier ko wapas — stock ghat jata hai',
      kpiCount: 'Returns', kpiValue: 'Debit value',
      party: 'supplier', partyLabel: 'Supplier',
      refLabel: 'Debit note no.',
      rateLabel: 'Cost', rateFrom: 'cost', emptyIcon: '⇧',
      emptyText: 'Supplier ko maal wapas karte waqt yahan likhein.',
      note: 'Post karte hi stock ghat jata hai aur supplier ka payable kam ho jata hai.'
    },

    /* ---------------- Return / Refund / Void ---------------- */
    returns: {
      type: 'RET', title: 'Return / Refund',
      addLabel: '+ New return', postLabel: 'Refund',
      listTitle: 'Customer returns', listSub: 'Bill wapas — stock wapas aata hai aur udhaar halka hota hai',
      kpiCount: 'Returns', kpiValue: 'Refund value',
      party: 'sale', partyLabel: 'Bill',
      rateLabel: 'Price', rateFrom: 'retail', emptyIcon: '↩',
      emptyText: 'Customer maal wapas laye to yahan se return karein.',
      note: 'Asal bill chunein taake record us se jur jaye. Post karte hi maal stock mein ' +
            'wapas aa jata hai; bill udhaar par tha to customer ka khata bhi halka ho jata hai.'
    },

    /* ---------------- Physical Stock Count ---------------- */
    count: {
      type: 'CNT', title: 'Physical Stock Count',
      addLabel: '+ New count', postLabel: 'Post adjustment',
      listTitle: 'Stock counts', listSub: 'Shelf ki ginti aur system ka farq',
      kpiCount: 'Counts', priced: false,
      partyLabel: 'Reference', refLabel: 'Area / aisle', refHint: 'misal: Aisle 3 — Bakery',
      emptyIcon: '▦', emptyText: 'Shelf ginne ke baad yahan likhein.',
      note: 'Sirf <b>shelf par jitna mila</b> wo likhein — system ka number khud aa jata hai ' +
            'aur farq khud nikal aata hai. Post karte hi stock us ginti ke barabar ho jata hai ' +
            'aur farq Stock Movement par <b>COUNT</b> ke naam se nazar aata hai.'
    },

    /* ---------------- Damage / Expiry Write-off ---------------- */
    wastage: {
      type: 'WST', title: 'Damage / Expiry Write-off',
      addLabel: '+ New write-off', postLabel: 'Write off',
      listTitle: 'Write-offs', listSub: 'Kharab, expired ya toota hua maal — stock se nikal jata hai',
      kpiCount: 'Write-offs', kpiValue: 'Loss value',
      partyLabel: 'Reason', refLabel: 'Reason', refHint: 'Expired / Damaged / Broken / Theft',
      rateLabel: 'Cost', rateFrom: 'cost', emptyIcon: '\u2298',
      emptyText: 'Kharab maal nikalte waqt yahan likhein.',
      note: 'Expiring batches <b>Batch &amp; Expiry</b> par nazar aate hain \u2014 wahan se dekh kar ' +
            'yahan write-off karein. Post karte hi stock ghat jata hai aur ' +
            '<b>Stock Movement</b> report par WASTAGE ke naam se record ho jata hai.'
    },

    /* ---------------- Purchase Order ---------------- */
    po: {
      type: 'PO', title: 'Purchase Orders',
      addLabel: '+ New order', postLabel: 'Send order',
      listTitle: 'Purchase orders', listSub: 'Supplier ko bheja gaya order — maal GRN par aata hai',
      kpiCount: 'Orders', kpiValue: 'Ordered value',
      party: 'supplier', partyLabel: 'Supplier',
      refLabel: 'Expected date',
      rateLabel: 'Cost', rateFrom: 'cost', emptyIcon: '▤',
      emptyText: 'Supplier ko order bhejne se pehle yahan banayein.',
      note: 'Order sirf ek <b>waada</b> hai — is se stock nahi hilta. Maal aane par ' +
            '<b>Goods Receipt</b> banayein; stock wahan barhta hai.'
    }
  };
})();

// build: V154 build 2026-10-13

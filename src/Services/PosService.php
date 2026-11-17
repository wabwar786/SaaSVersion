<?php
namespace Aio\Services;
use Aio\DB;use PDO;
final class PosService {
    /**
     * V70 — bill ko us waqt ki OPEN shift se joro.
     * POS ki screen `shift_id` bhejti hi nahi thi, is liye har payment
     * `shift_id = NULL` ke saath jata tha aur shift close karte waqt
     * "expected cash" kabhi theek nahi banta tha. Ab server khud
     * cashier ki open shift dhoond leta hai.
     */
    private static function shiftFor(\PDO $p, array $d): ?string
    {
        /* ============================================================
           V189 — BAND HO CHUKE ACCOUNT PAR BILL NAHI LIKHA JAYEGA.

           Pehle client ka bheja hua `shift_id` bina dekhe maan liya jata
           tha. POS ka safha ghanton khula rehta hai, aur account kahin
           aur se band ho sakta hai — cashier khud band kare, ya ab V183
           ke baad ADMIN doosri screen se band kar de.

           Us soorat mein POS purana `shift_id` bhejta rehta tha aur naye
           bill BAND account par likhe jate thay. Nateeja wahi jo malik ne
           pakra: "bill add howa aur auto khatam ho gaya, na duplicate
           mein hai na hold mein." Bill database mein salamat hota tha,
           magar Hold Bills aur Duplicate dono CHALTE account ko dekhte
           hain — is liye wo kahin nazar nahi aata tha. Aur us ki sale
           band account ke khaate mein chali jati thi.

           Chala kar dekha:
               POS ke paas account 600  ->  account band, 601 khula
               POS ab bhi 600 bhejta raha
               bill 0004 -> BAND account 600 par
               POS reload -> hold 0, duplicate 0, DB mein 3 bill pare

           AB: jo shift_id aaye wo pehle parkha jata hai. Khula ho to
           wohi; band ya ghayab ho to CHALTA account istemal hota hai.
           `hold()` aur `finalize()` apne jawab mein asli shift bhejte
           hain, to POS khud ko durust kar leta hai (dekho `syncShift()`).
           ============================================================ */
        $given = trim((string)($d['shift_id'] ?? ''));
        if ($given !== '') {
            try {
                $q = $p->prepare("SELECT id FROM cashier_shifts
                                   WHERE id=? AND site_id=? AND status='OPEN'
                                     AND deleted_at IS NULL LIMIT 1");
                $q->execute([$given, site_id()]);
                if ($q->fetchColumn()) return $given;
            } catch (\Throwable $e) { return $given; }
            /* Band ya ghayab — neeche chalta account dhoonda jayega. */
        }
        try {
            $q = $p->prepare("SELECT id FROM cashier_shifts
                               WHERE site_id=? AND cashier_user_id=? AND status='OPEN'
                                 AND deleted_at IS NULL
                               ORDER BY opened_at DESC LIMIT 1");
            $q->execute([site_id(), current_user()['id'] ?? null]);
            $id = $q->fetchColumn();
            if ($id) return (string)$id;
            /* Cashier ki apni shift na ho to branch ki koi bhi open shift —
               chhoti jagahon par ek hi till hoti hai. */
            $q2 = $p->prepare("SELECT id FROM cashier_shifts
                                WHERE site_id=? AND status='OPEN' AND deleted_at IS NULL
                                ORDER BY opened_at DESC LIMIT 1");
            $q2->execute([site_id()]);
            return ($v = $q2->fetchColumn()) ? (string)$v : null;
        } catch (\Throwable $e) { return null; }
    }

 /**
  * V190 — BILL KA HISAAB LIKHNE KI EK HI JAGAH.
  *
  * Yeh hissa pehle sirf `hold()` ke andar tha. `sendKot()` sirf items
  * likhta tha aur raqam ko haath hi nahi lagata tha — service charge,
  * discount, delivery aur TOTAL sab sifar pare rehte thay:
  *
  *     Send to Kitchen ke baad : svc 0 · disc 0 · total 0 · items 1
  *     Hold ke baad            : svc 300 · disc 50 · total 1,950
  *
  * Nateeja: kitchen bhejne ke baad agar bill chhor diya jaye (software
  * band ho jaye, ya cashier aage nikal jaye) to database mein wo bill
  * SIFAR ka khara reh jata tha. Hold Bills mein "PKR 0" wala purana
  * masla bhi yehi tha. Aur jo kagaz customer ke paas gaya us par 1,950
  * likha hota tha — system aur parchi alag alag.
  *
  * Ab dono raaste yehi ek method istemal karte hain, to aage chal kar
  * un ka hisaab alag ho hi nahi sakta (V168 wala sabaq).
  *
  * @param  int $hold `orders.hold_status` — hold par 1, KOT par 0.
  * @return float grand total
  */
 private static function writeTotals(PDO $p, string $order, array $d, int $hold): float
 {
    /* V94 — TOTALS BHI BHARO.
       Pehle hold sirf items likhta tha; `subtotal` aur `grand_total`
       sifar pare rehte. Counter par tablet ka bill "PKR 0" dikhta tha,
       aur cashier ko andaza hi nahi hota tha ke us table ka kitna bana.
       Ab totals items se ginte hain — wahi raqam jo bill par aayegi. */
    $tq=$p->prepare("SELECT COALESCE(SUM(qty*unit_price),0) FROM order_items
                      WHERE order_id=? AND status='ACTIVE'");
    $tq->execute([$order]);
    $sub=(float)$tq->fetchColumn();
    $disc=(float)($d['discount_amount']??0);
    $svc=(float)($d['service_charge']??0);
    /* V199 — 400 ke bill par 1,000 discount: bill 0 theek tha, magar
       discount 1,000 save hota aur discount reports 600 zyada dikhati.
       Ab discount zyada se zyada bill (items + service) jitna. */
    $disc=min(max(0.0,$disc),max(0.0,$sub+$svc));
    /* V171 — delivery charge. Sirf Home Delivery par; `deliveryOf()`
       khud dekh leta hai ke bill ka type kya hai. */
    $dlv=self::deliveryOf($d);

    /* ============================================================
       V168 — HOLD KA TOTAL AB WAHI HISAAB SE BANTA HAI JO BILL KA.

       Pehle yahan: grand = sub - disc + svc + tax  — tax HAMESHA
       upar se joRa jata tha. Pakistan mein tax INCLUSIVE hota hai,
       yani keemat mein pehle se shamil. Nateeja: 420 ka bill hold
       karo to Hold Bills mein 477.93 likha aata tha. Discount wale
       bill par farq aur bara lagta tha, is liye shikayat discount ki
       shakal mein aati thi — halanke discount theek tha, TOTAL ghalat
       tha.

       Ab wahi `TaxMode::split()` jo `finalize()` istemal karti hai:
         INCLUSIVE -> tax usi raqam ke andar se nikalta hai
         EXCLUSIVE -> tax upar se lagta hai
       Yani hold ka total aur bill ka total hamesha barabar.
       ============================================================ */
    $taxable = max(0.0, $sub - $disc + $svc);
    $rate    = (float)($d['tax_rate'] ?? 0);
    if ($rate > 0) {
        $sp  = \Aio\Services\TaxMode::split($taxable, $rate);
        $tax = $sp['tax'];
        $grand = \Aio\Services\TaxMode::isInclusive() ? $taxable : ($taxable + $tax);
    } else {
        /* Purana POS rate nahi bhejta — wahi raasta jo finalize leta hai. */
        $tax   = (float)($d['tax_amount'] ?? 0);
        $grand = $taxable + $tax;
    }
    /* V171 — DELIVERY CHARGE TAX KE BAAD JURTA HAI, PEHLE NAHI.
       Malik ka faisla: tax sirf khane par banta hai. Is liye yeh
       `taxable` mein nahi gaya, warna customer se charge par bhi tax
       wasool hota. Yeh lakeer `finalize()` mein bhi bilkul isi jagah,
       bilkul isi tarah hai — warna hold ka total aur bill ka total
       alag ho jayen (V168 wala masla dobara). */
    $grand = $grand + $dlv;
    /* V157 — PERCENT BHI MEHFOOZ.
       Pehle sirf `discount_amount` likha jata tha. Bill hold kar ke
       wapas kholne par cashier ka 10% gayab ho jata aur us ki jagah usi
       raqam ka FLAT discount aa jata — aur agar us ke baad items badal
       jayen to wo raqam ghalat ho jati. Ab percent alag se likha jata
       hai, to unhold par "10%" hi wapas aata hai. */
    $dpct=(float)($d['discount_pct']??0);
    /* `hold_status` ab waqai likha jata hai. Running Orders screen par
       "Held" ka khana isay parhta tha magar likhta koi nahi tha — wo
       ginti hamesha 0 rehti thi. */
    $p->prepare("UPDATE orders SET subtotal=?, tax_amount=?, discount_amount=?,
                     discount_pct=?, service_charge=?, delivery_charge=?, grand_total=?,
                     hold_status=1, updated_at=NOW(6)
                  WHERE id=?")
      ->execute([$sub,$tax,$disc,$dpct,$svc,$dlv,max(0.0,$grand),$order]);
    $p->prepare("UPDATE orders SET subtotal=?, tax_amount=?, discount_amount=?,
                     discount_pct=?, service_charge=?, delivery_charge=?, grand_total=?,
                     hold_status=?, updated_at=NOW(6)
                  WHERE id=?")
      ->execute([$sub,$tax,$disc,$dpct,$svc,$dlv,max(0.0,$grand),$hold,$order]);
    return max(0.0, $grand);
 }

 /**
  * V72 — HOLD: bill khula rakho, kitchen ko kuch mat bhejo.
  * Tablet ka order taker items punch karta hai aur bill table par
  * "hold" rehta hai; kitchen tab jata hai jab wo Send dabata hai.
  * Pehle hold ka koi server-side rasta tha hi nahi.
  */
 public static function hold(array $d,array $items): array { return self::txRetry(function(PDO $p)use($d,$items){
    self::lockSite($p);   /* V197 — branch ke saves ek qatar mein */
    $order=self::ensureOpenOrder($p,$d);
    $n=0; $keep=[];
    foreach($items as $i){ $row=self::upsertOrderItem($p,$order,$i); $keep[]=(string)$row['id']; $n++; }
    self::dropRemoved($p,$order,$d,$keep);   /* V196 — cart se hata hua item bill se bhi hatao */

    $grand = self::writeTotals($p, $order, $d, 1);
    $rev = self::bumpRev($p, $order);          /* V197 */

    /* Table `ensureOpenOrder` khud set karta hai (id ya naam se). */

    self::queueSync($p,'orders',$order,'UPDATE');
    /* V189 — asli shift wapas bhejo. POS us se khud ko durust kar leta
       hai jab account kahin aur se band ho chuka ho. */
    return ['order_id'=>$order,'held'=>$n,'bill_no'=>self::billOf($p,$order),'line_ids'=>$keep,'rev'=>$rev,
            'shift_id'=>(string)(self::shiftFor($p,$d) ?? ''),
            'total'=>max(0.0,$grand)];
 }); }

 /** Order ka asli bill number — guard number badal de to POS ko bata sakein. */
 private static function billOf(PDO $p,string $order):string{
    try{ $q=$p->prepare("SELECT bill_no FROM orders WHERE id=? LIMIT 1");
         $q->execute([$order]); return (string)($q->fetchColumn()?:''); }
    catch(\Throwable $e){ return ''; }
 }

 public static function sendKot(array $d,array $items): array { return self::txRetry(function(PDO $p)use($d,$items){self::lockSite($p);/* V197 */$order=self::ensureOpenOrder($p,$d);$pending=[];$keep=[];foreach($items as $i){$row=self::upsertOrderItem($p,$order,$i);$keep[]=(string)$row['id'];$pd=max(0,(float)$row['qty']-(float)$row['sent_qty']);if($pd>0)$pending[]=['row'=>$row,'pending'=>$pd,'input'=>$i];}
    /* V190 — KOT par bhi poora hisaab likho, sirf items nahi.
       Pehle yahan raqam ko haath hi nahi lagta tha: kitchen bhejne ke
       baad bill DB mein svc 0 / disc 0 / total 0 ka khara rehta tha.
       Ab wahi ek method jo hold istemal karta hai — `hold_status` 0,
       kyunki yeh bill ruka hua nahi, chal raha hai. */
    self::dropRemoved($p,$order,$d,$keep);   /* V196 */
    self::writeTotals($p,$order,$d,0);
    $rev=self::bumpRev($p,$order);   /* V197 */
    if(!$pending)return ['order_id'=>$order,'sent'=>0,'bill_no'=>self::billOf($p,$order),'line_ids'=>$keep,'rev'=>$rev,
                         'shift_id'=>(string)(self::shiftFor($p,$d) ?? '')];$groups=[];$noPrinter=[];
 foreach($pending as $x){
   $pr=self::printerForMenu($p,$x['row']['menu_item_id']);
   /* Printer bilkul hi na ho (abhi tak koi nahi banaya) to item ko
      khamoshi se chhorna sab se bura hai — cashier samajhta hai ke
      order chala gaya. Aise items alag rakho aur phir bhi "sent" karo,
      taake screen par sach nazar aaye. */
   if(!$pr){$noPrinter[]=$x;continue;}
   $groups[$pr['id']]['printer']=$pr;$groups[$pr['id']]['items'][]=$x;
 } $sent=0;foreach($groups as $g){$kt=uuid();$ticket='KOT-'.strtoupper(substr(str_replace('-','',$kt),0,8));$p->prepare("INSERT INTO kitchen_tickets(id,tenant_id,site_id,order_id,ticket_no,printer_id,station_code,ticket_status,sent_at,created_by_user_id) VALUES(?,?,?,?,?,?,?,'NEW',NOW(6),?)")->execute([$kt,tenant_id(),site_id(),$order,$ticket,$g['printer']['id'],$g['printer']['station_code'],current_user()['id']??null]);$lines=[];foreach($g['items'] as $x){$p->prepare("INSERT INTO kitchen_ticket_items(id,kitchen_ticket_id,order_item_id,qty_sent,item_status,note_snapshot) VALUES(?,?,?,?, 'NEW',?)")->execute([uuid(),$kt,$x['row']['id'],$x['pending'],$x['row']['kitchen_note']]);$p->prepare("UPDATE order_items SET sent_qty=qty,updated_at=NOW(6) WHERE id=?")->execute([$x['row']['id']]);$lines[]=$x['pending'].' x '.$x['row']['item_name_snapshot'];$sent++;}$payload=$ticket."\n".implode("\n",$lines);$p->prepare("INSERT INTO printer_jobs(id,tenant_id,site_id,printer_id,job_type,reference_type,reference_id,payload_text,status) VALUES(?,?,?,?, 'KOT','KITCHEN_TICKET',?,?, 'PENDING')")->execute([uuid(),tenant_id(),site_id(),$g['printer']['id'],$kt,$payload]);} /* Jin items ka koi printer hi nahi, unhen bhi sent karo — warna wo
    hamesha "pending" pare rehte aur cashier ko lagta ke kuch gaya hi
    nahi. Sath mein saaf batao ke kaghaz nahi nikla. */
 foreach($noPrinter as $x){
   $p->prepare("UPDATE order_items SET sent_qty=qty, updated_at=NOW(6) WHERE id=?")
     ->execute([$x['row']['id']]);
   $sent++;
 }
 self::queueSync($p,'orders',$order,'UPDATE');
 return ['order_id'=>$order,'sent'=>$sent,
         /* V190 — POS ko asli bill number aur asli account bhi batao,
            taake wo khud ko durust kar sake (dekho `syncShift`). */
         'bill_no'=>self::billOf($p,$order),'line_ids'=>$keep,'rev'=>$rev,
         'shift_id'=>(string)(self::shiftFor($p,$d) ?? ''),
         'no_printer'=>count($noPrinter),
         'message'=>$noPrinter
           ? ('Order sent, but '.count($noPrinter).' item(s) had no kitchen printer. '
              .'Add a printer under Printers / Devices so the kitchen gets a paper copy.')
           : ''];}); }
 public static function finalize(array $d,array $items): string { return self::txRetry(function(PDO $p)use($d,$items){self::lockSite($p);/* V197 */$order=self::ensureOpenOrder($p,$d);$subtotal=0;$keep=[];foreach($items as $i){$row=self::upsertOrderItem($p,$order,$i);$keep[]=(string)$row['id'];$subtotal+=(float)$row['qty']*(float)$row['unit_price'];}self::dropRemoved($p,$order,$d,$keep);/* V196 */self::bumpRev($p,$order);/* V197 */$discount=(float)($d['discount_amount']??0);$service=(float)($d['service_charge']??0);$discount=min(max(0.0,$discount),max(0.0,$subtotal+$service));/* V199 — discount bill se bara save na ho *//* ============================================================
        TAX — ab server par hi banta hai.

        Pehle POS browser jo `tax_amount` bhej deta tha wahi mehfooz ho
        jata tha. Do masle: (1) do POS alag alag hisaab bhej sakte the,
        (2) jo raqam FBR par jati hai us par server ka koi control nahi
        tha. Bill ka hisaab hamesha ek hi jagah hona chahiye.

        INCLUSIVE: rate mein tax pehle se shamil hai — usi mein se nikalta
        hai, customer se extra kuch nahi liya jata.
        EXCLUSIVE: rate tax se pehle ka hai — tax upar se lagta hai.
        ============================================================ */
        /* ============================================================
           V168 — DISCOUNT BILL SE BARA HO TO BILL SIFAR, MANFI NAHI.

           Pehle sirf TAX ke hisaab par `max(0,...)` lagta tha, magar
           `$taxable` khud manfi reh jata — aur `$grand` usi se banta
           tha. Nateeja: 840 ke bill par 1,340 ka discount lagao to
           bill -500 ka ban jata, aur usi raqam ki payment bhi likh di
           jati. Ek aisi entry jo kisi report mein theek nahi baithti.

           Discount bill se bara ho hi nahi sakta — wo bill ko sifar
           tak le ja sakta hai, us se aage nahi.
           ============================================================ */
        $taxable = max(0.0, $subtotal - $discount + $service);
        $rate    = (float)($d['tax_rate'] ?? 0);
        $sp      = \Aio\Services\TaxMode::split($taxable, $rate);
        $inclusive = \Aio\Services\TaxMode::isInclusive();
        $tax     = $sp['tax'];
        /* Purana POS rate nahi bhejta — us soorat mein uska apna tax lo,
           warna wo build achanak bina tax ke bill banane lagta. */
        if ($rate <= 0) { $tax = (float)($d['tax_amount'] ?? 0); $inclusive = false; }
        $grand = $inclusive ? $taxable : ($taxable + $tax);
        /* V171 — DELIVERY CHARGE TAX KE BAAD. `hold()` mein bhi bilkul
           yehi lakeer, bilkul isi jagah hai. Dono ek jaise na hon to
           hold ka total aur bill ka total alag ho jayen — V168 wala
           masla, jis ki shikayat "discount theek nahi" ki shakal mein
           aati thi. */
        $delivery = self::deliveryOf($d);
        $grand    = $grand + $delivery;$received=(float)($d['received_amount']??$grand);$p->prepare("UPDATE orders SET order_status='CLOSED',payment_status='PAID',subtotal=?,discount_amount=?,discount_pct=?,service_charge=?,delivery_charge=?,tax_amount=?,grand_total=?,paid_amount=?,change_amount=?,hold_status=0,closed_at=NOW(6),updated_at=NOW(6) WHERE id=?")->execute([$subtotal,$discount,(float)($d['discount_pct']??0),$service,$delivery,$tax,$grand,$grand,max(0,$received-$grand),$order]);$exists=$p->prepare("SELECT COUNT(*) FROM payments WHERE order_id=? AND status='COMPLETED'");$exists->execute([$order]);if(!(int)$exists->fetchColumn()){$pm=$p->prepare("SELECT id FROM payment_methods WHERE site_id=? AND code=? AND is_active=1 LIMIT 1");$pm->execute([site_id(),self::paymentCode($d['payment_code']??'Cash')]);$pmid=$pm->fetchColumn();if(!$pmid)throw new \RuntimeException('Payment method not configured');$p->prepare("INSERT INTO payments(id,tenant_id,site_id,order_id,shift_id,payment_method_id,amount,received_amount,change_amount,status,paid_at,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,'COMPLETED',NOW(6),?)")->execute([uuid(),tenant_id(),site_id(),$order,self::shiftFor($p,$d),$pmid,$grand,$received,max(0,$received-$grand),current_user()['id']??null]);}
  $st=$p->prepare("SELECT COUNT(*) FROM stock_transactions WHERE reference_type='ORDER' AND reference_id=? AND transaction_type='SALE'");$st->execute([$order]);if(!(int)$st->fetchColumn()){self::postInventory($p,$order,$d['bill_no'],$items);}self::sendPendingInsideTx($p,$order);self::queueSync($p,'orders',$order,'UPDATE');return $order;}); }
 private static function ensureOpenOrder(PDO $p,array $d):string{
    /* ============================================================
       V168 — RUKAY HUE BILL KO US KI ID SE PAKRO, NUMBER SE NAHI.

       DO ASLI KHARABIYAN YAHIN SE NIKALTI THIN:

       1. BAND BILL DOBARA LIKHA JATA THA.
          Neeche wali talash mein `order_status` ki koi shart nahi thi.
          Yani agar kisi band (CLOSED) bill ke number par `pos-hold`
          aa jaye, to yeh usi band bill ko laut deta aur us par nayi
          items aur naya total likh diya jata. Customer 640 de kar ja
          chuka hota aur kitab mein 2,740 likha ja chuka hota. Payment
          wahi 640 rehti — din ka hisaab kabhi milta hi nahi.
          (`pos_bill_guard()` yeh rokta hai, magar wo `pos-hold` par
           lagaya hi nahi gaya tha.)

       2. KAL KA ROKA HUA BILL AAJ BAND KARNE PAR DO ORDER BAN JATE.
          Talash `business_date = aaj` par thi. Kal ka hold aaj nahi
          milta, is liye USI NUMBER PAR AAJ KA NAYA ORDER ban jata,
          payment us par lagti — aur KAL WALA ORDER KHULA HI REH JATA.
          Wahi "bill band kiya, phir bhi Hold mein baitha hai".

       ILAJ: jab POS kisi ruke hue bill par kaam kar raha ho to wo us
       ka `order_id` bhejta hai. Id se pakra gaya order wahi ek order
       rehta hai — chahe us ki tareekh kuch bhi ho. Aur number se
       talash ab SIRF khule orders mein hoti hai.
       ============================================================ */
    $oid = trim((string)($d['order_id'] ?? ''));
    $wantId = null;
    if ($oid !== '' && preg_match('/^[0-9a-f-]{36}$/i', $oid)) {
        /* ============================================================
           V197 — ID SE MILA BILL: KHULA HAI TO WOHI, BAND HAI TO RUKO.

           Pehle: id wala bill band mila to neeche ka raasta NAYA bill
           bana deta. Do counters ne ek held bill band kiya, ya payment
           ka jawab net mein gum hua aur cashier ne dobara dabaya —
           dono soorat mein DO SALE. Ab band bill par koi naya bill
           nahi banta (PosConflict CLOSED).

           `rev`: screen ne bill jis version par dekha tha. Bill us ke
           baad kahin aur badla ho to save rukta hai (CHANGED) — purani
           screen doosre ka kaam nahi mita sakti. Tablet `rev` nahi
           bhejta: us par yeh shart nahi lagti (wo sirf jorta hai).

           Naya POS bill shuru hote hi apni id banata hai. Woh id abhi
           database mein na ho to naya order ISI id se banta hai — taake
           wohi bill dobara aaye (double click, net ka masla) to wohi
           order mile, doosra nahi.
           ============================================================ */
        $qo = $p->prepare("SELECT id, order_status, bill_no, row_version FROM orders
                            WHERE id=? AND site_id=? LIMIT 1 FOR UPDATE");
        $qo->execute([$oid, site_id()]);
        if ($o = $qo->fetch(PDO::FETCH_ASSOC)) {
            $bn = (string)$o['bill_no'];
            if ($o['order_status'] === 'OPEN') {
                $rev = trim((string)($d['rev'] ?? ''));
                if ($rev !== '' && (int)$rev !== (int)$o['row_version']) {
                    throw new PosConflict('CHANGED',
                        'Bill #'.$bn.' doosri jagah (counter / tablet) badla gaya hai. '
                       .'Taaza bill khol diya gaya hai — check kar ke dobara karein.', $bn, (string)$o['id']);
                }
                self::touchOrder($p, (string)$o['id'], $d);
                return (string)$o['id'];
            }
            throw new PosConflict('CLOSED',
                $o['order_status'] === 'CLOSED'
                  ? 'Bill #'.$bn.' pehle hi band (paid) ho chuka hai — dobara charge nahi hua.'
                  : 'Bill #'.$bn.' cancel ho chuka hai — is par kaam nahi ho sakta.',
                $bn, (string)$o['id']);
        }
        $ex = $p->prepare("SELECT COUNT(*) FROM orders WHERE id=?");
        $ex->execute([$oid]);
        if ((int)$ex->fetchColumn() === 0) $wantId = $oid;
    }

    /* V72 — TABLET table se kaam karta hai, bill number se nahi.
       Pehle yahan `$d['bill_no']` lazmi tha; khali aane par khali bill
       number wala order ban jata tha. Ab: bill_no na ho to us table ka
       khula bill dhoondo, aur wo bhi na ho to naya number khud banao. */
    /* V197 — TABLET: table ka khula bill HAMESHA pehle dhoondo.
       Tablet table ka bill number bhi bhejta hai. V195 ke baad
       "number pehle se maujood -> naya number" wali shart lagne se
       tablet ka har naya item ALAG bill ban jata tha (test: R-01 par
       0428, 0429, 0430 — teen khule bill). Ab `table_id` aaye to us
       table ka khula bill hi istemal hota hai, number kuch bhi ho. */
    {
        $tid = (string)($d['table_id'] ?? '');
        if ($tid !== '' && $wantId === null) {
            /* V168 — table ka khula bill ab apni ID se pakRa jata hai.
               Pehle sirf us ka NUMBER liya jata tha, aur neeche wali
               talash "aaj ki tareekh" par hoti thi. Raat ka khula hua
               table subah usi number par NAYA order bana deta, aur
               raat wala order hamesha ke liye khula reh jata — wahi
               "band kiya, phir bhi Hold mein hai" wala masla, magar
               tablet/table ke raaste se. */
            $q0 = $p->prepare("SELECT id FROM orders
                                WHERE site_id=? AND table_id=? AND order_status='OPEN'
                                ORDER BY created_at DESC LIMIT 1");
            $q0->execute([site_id(), $tid]);
            if ($openId = $q0->fetchColumn()) {
                self::touchOrder($p, (string)$openId, $d);
                return (string)$openId;
            }
        }
        if (trim((string)($d['bill_no'] ?? '')) === '') {
            $d['bill_no'] = PageData::billPrefix() . str_pad((string)PageData::nextBill(), 4, '0', STR_PAD_LEFT);
        }
    }
    $bill=ltrim((string)$d['bill_no'],'#');
    /* ============================================================
       SIRF KHULA ORDER. Band (CLOSED) ya mansookh (VOID) bill par
       dobara likhna sab se mehnga bug tha — paisa le liya gaya bill
       chup chaap badal jata tha.

       Aur agar us number par koi band bill pehle se maujood ho to
       usi number par naya order banana bhi ghalat hai (ek number, do
       bill). Aise mein naya number le liya jata hai — wahi kaam jo
       `pos_bill_guard()` karta hai, magar ab HAR raaste par.
       ============================================================ */
    /* ============================================================
       V195 — NAYA BILL KABHI KISI DOOSRE ORDER MEIN NAHI MILTA.

       JO HOTA THA (customer: "bill gum ho jata hai"):
         Counter A aur Counter B dono ki screen par agla number 0001.
         B ne table R-01 ka KOT bheja -> 0001 ek KHULA order ban gaya.
         A ne walk-in 0001 band kiya -> yeh talash KHULE 0001 ko pakar
         leti, A ka item USI order mein jorti aur order BAND kar deti.
         Table R-01 ka bill running/hold se ghayab, us ke 3 item walk-in
         customer ke bill mein. (Test: dono ka order id ek, table ka
         order CLOSED, walk-in bill mein 2 lines.)

       AB: jis bill ke saath order id nahi aayi (yani yeh kisi hold /
       KOT / table ka bill nahi), wo hamesha NAYA order hai. Number
       kisi aur order ka ho (khula ho ya band) to naya number.
       Hold, KOT aur table wale bill apni ID se aate hain (upar) —
       un par koi asar nahi.

       Number dene se pehle branch (sites) ki row par lock: ek waqt
       mein ek hi counter agla number leta hai (retail V193 jaisa).
       Lock sirf is transaction tak rehta hai.
       ============================================================ */
    $p->prepare("SELECT id FROM sites WHERE id=? FOR UPDATE")->execute([site_id()]);
    $q=$p->prepare("SELECT id FROM orders
                     WHERE site_id=? AND business_date=? AND bill_no=? LIMIT 1");
    $q->execute([site_id(),today(),$bill]);
    $id=null;
    if($q->fetchColumn()){
        $bill = PageData::billPrefix().str_pad((string)PageData::nextBill(),4,'0',STR_PAD_LEFT);
    }
    /* V94 — TABLE: id ya naam, dono chalein.
       Pehle sirf `table_name` dekha jata tha. Tablet aur QR ordering
       `table_id` bhejte hain (unke paas asli id hoti hai), is liye
       table hamesha NULL reh jata tha — counter par hold bill mein
       table ka khana khali aata aur "kis table ka bill hai" pata hi
       nahi chalta. */
    $table=null;
    $rawTable=trim((string)($d['table_id']??''));
    if($rawTable!=='' && preg_match('/^[0-9a-f-]{36}$/i',$rawTable)){
        $tq=$p->prepare("SELECT id FROM dining_tables WHERE id=? AND site_id=? LIMIT 1");
        $tq->execute([$rawTable,site_id()]);
        $table=$tq->fetchColumn()?:null;
    }
    if(!$table) $table=self::tableId($p,$d['table_name']??$d['table']??'');
    $customer=null;
    $rawCustomer=(string)($d['customer_id']??'');
    if($rawCustomer && $rawCustomer!=='walkin'){
        if(preg_match('/^[0-9a-f-]{36}$/i',$rawCustomer)){
            $cq=$p->prepare("SELECT id FROM customers WHERE id=? AND tenant_id=? LIMIT 1");
            $cq->execute([$rawCustomer,tenant_id()]);$customer=$cq->fetchColumn()?:null;
        }
        if(!$customer && !empty($d['customer_name'])){
            $cq=$p->prepare("SELECT id FROM customers WHERE tenant_id=? AND full_name=? LIMIT 1");
            $cq->execute([tenant_id(),$d['customer_name']]);$customer=$cq->fetchColumn()?:null;
        }
    }
    $mode=self::modeCode($d['service_mode']??'Dine In');
    if($id){
        self::touchOrder($p,(string)$id,$d);
        return$id;
    }
    $id=$wantId ?: uuid();
    $p->prepare("INSERT INTO orders(id,tenant_id,site_id,bill_no,business_date,order_source,service_mode,order_status,payment_status,table_id,customer_id,shift_id,guest_count,opened_at,created_by_user_id) VALUES(?,?,?,?,?,'POS',?,'OPEN','UNPAID',?,?,?,?,NOW(6),?)")->execute([$id,tenant_id(),site_id(),$bill,today(),$mode,$table,$customer,self::shiftFor($p,$d),($d['guest_count']??null)?:null,current_user()['id']??null]);
    return$id;
 }
 /* ============================================================
    V168 — khule order ka halat (table, customer, mode, shift) taza
    karna. Pehle yeh seedha `ensureOpenOrder` ke andar likha tha; ab
    do raste us tak aate hain (id se aur number se), is liye ek jagah.
    ============================================================ */
 private static function touchOrder(PDO $p,string $id,array $d):void{
    $table=null;
    $rawTable=trim((string)($d['table_id']??''));
    if($rawTable!=='' && preg_match('/^[0-9a-f-]{36}$/i',$rawTable)){
        $tq=$p->prepare("SELECT id FROM dining_tables WHERE id=? AND site_id=? LIMIT 1");
        $tq->execute([$rawTable,site_id()]);
        $table=$tq->fetchColumn()?:null;
    }
    if(!$table) $table=self::tableId($p,$d['table_name']??$d['table']??'');
    $customer=null;
    $rawCustomer=(string)($d['customer_id']??'');
    if($rawCustomer && $rawCustomer!=='walkin'){
        if(preg_match('/^[0-9a-f-]{36}$/i',$rawCustomer)){
            $cq=$p->prepare("SELECT id FROM customers WHERE id=? AND tenant_id=? LIMIT 1");
            $cq->execute([$rawCustomer,tenant_id()]);$customer=$cq->fetchColumn()?:null;
        }
        if(!$customer && !empty($d['customer_name'])){
            $cq=$p->prepare("SELECT id FROM customers WHERE tenant_id=? AND full_name=? LIMIT 1");
            $cq->execute([tenant_id(),$d['customer_name']]);$customer=$cq->fetchColumn()?:null;
        }
    }
    $mode=self::modeCode($d['service_mode']??'Dine In');
    $p->prepare("UPDATE orders SET service_mode=?,table_id=?,customer_id=?,shift_id=?,guest_count=?,updated_at=NOW(6) WHERE id=?")
      ->execute([$mode,$table,$customer,self::shiftFor($p,$d),($d['guest_count']??null)?:null,$id]);
 }
 private static function resolveMenuId(PDO $p,array $i):string{
    $raw=(string)($i['menu_item_id']??$i['id']??'');
    if($raw && preg_match('/^[0-9a-f-]{36}$/i',$raw)){
        $q=$p->prepare("SELECT id FROM menu_items WHERE id=? AND site_id=? AND deleted_at IS NULL LIMIT 1");
        $q->execute([$raw,site_id()]);
        if($id=$q->fetchColumn())return$id;
    }
    $base=trim((string)($i['base_name']??$i['name']??''));
    $q=$p->prepare("SELECT id FROM menu_items WHERE site_id=? AND name=? AND deleted_at IS NULL LIMIT 1");
    $q->execute([site_id(),$base]);
    if($id=$q->fetchColumn())return$id;
    foreach([' Large',' Medium',' Small'] as $suffix){
        if(str_ends_with($base,$suffix)){
            $n=substr($base,0,-strlen($suffix));
            $q->execute([site_id(),$n]);
            if($id=$q->fetchColumn())return$id;
        }
    }
    throw new \RuntimeException('Menu item not found in database: '.($i['name']??$base));
 }
 /* ============================================================
    V196 — CART SE HATAYA HUA ITEM BILL SE BHI HATAO.

    Customer ne pakra: bill mein se item hatao, hold karo, wapas kholo
    -> hataya hua item wapas aa jata. Wajah: hold / KOT / bill band
    karte waqt server sirf cart wali lines likhta tha. Jo line cart se
    nikal gayi, us ko koi chhuta hi nahi tha — database mein ACTIVE
    pari rehti. Resume par wapas, aur bill band hone ke baad bhi item
    reports mein.

    Ab POS `base_line_ids` bhejta hai: is bill ki wo lines jo screen par
    aayi thin (resume ya pichli KOT se). Un mein se jo is dafa cart mein
    nahi mili, woh VOID: qty 0, raqam 0. Delete nahi — kitchen ki parchi
    aur void log us line se jure hain, aur offline sync UPDATE hi le
    jata hai (delete cloud tak nahi pohanchta).

    Sirf `base_line_ids` wali lines. Tablet ne beech mein koi item daala
    ho to woh is fehrist mein hai hi nahi — us ko haath nahi lagta.
    ============================================================ */
 /* ============================================================
    V197 — RUSH MEIN BILL FAIL / ADHA NA HO.

    Cloud par kai requests ek saath chalti hain. 8 counters ek saath
    bill band karein to MariaDB "Deadlock" de deta tha: bill refuse, aur
    kabhi adha save (andar kahin error chup chaap pakra gaya, transaction
    khatam, baqi likhai bina transaction ke). Test: 96 mein se 1-45
    bills refuse, 5-7 khule bill bache.

    1. `lockSite`: har save ka PEHLA kaam branch ki row par lock. Ek
       branch ke saves ek qatar mein — aapas mein deadlock ho hi nahi
       sakta. (Shop PC par pehle hi ek waqt mein ek request hoti hai.)
    2. `txRetry`: phir bhi deadlock / lock-wait aaye to poora save
       dobara (3 dafa tak). Deadlock par MariaDB poora transaction
       wapas le leta hai, is liye dobara chalana mehfooz hai.
    3. Transaction beech mein khatam mili to save ko kamyab nahi maana
       jata — saaf error.
    ============================================================ */
 private static function lockSite(PDO $p):void{
    $p->prepare("SELECT id FROM sites WHERE id=? FOR UPDATE")->execute([site_id()]);
 }
 private static function txRetry(callable $fn):mixed{
    for($try=1;;$try++){
        try{
            return DB::tx(function(PDO $p)use($fn){
                $r=$fn($p);
                if(!$p->inTransaction())
                    throw new \RuntimeException('Bill save beech mein ruk gaya — dobara koshish karein.');
                return $r;
            });
        }catch(\PDOException $e){
            $code=(int)($e->errorInfo[1]??0);
            if($try<3 && ($code===1213 || $code===1205 || $e->getCode()==='40001')){
                usleep(random_int(20000,80000)); continue;
            }
            throw $e;
        }
    }
 }
 /** Bill ka version barhao — har save par (dekho PosConflict CHANGED). */
 private static function bumpRev(PDO $p,string $order):int{
    $p->prepare("UPDATE orders SET row_version=row_version+1, updated_at=NOW(6) WHERE id=?")->execute([$order]);
    $q=$p->prepare("SELECT row_version FROM orders WHERE id=?"); $q->execute([$order]);
    return (int)$q->fetchColumn();
 }
 private static function dropRemoved(PDO $p,string $order,array $d,array $keep):int{
    $base=[];
    foreach((array)($d['base_line_ids']??[]) as $x){ $x=trim((string)$x); if($x!=='')$base[$x]=1; }
    foreach($keep as $k) unset($base[$k]);
    if(!$base) return 0;
    /* V199 — kitchen ja chuki line hat rahi ho to pehle us ka naam aur
       kitchen wali qty le lo — kitchen ko cancel parchi jayegi. */
    $sq=$p->prepare("SELECT id,item_name_snapshot,sent_qty FROM order_items WHERE id=? AND order_id=? AND status='ACTIVE' AND sent_qty>0");
    $sent=[];
    foreach(array_keys($base) as $id){ $sq->execute([$id,$order]); if($r=$sq->fetch(PDO::FETCH_ASSOC)) $sent[]=$r; }
    $u=$p->prepare("UPDATE order_items SET status='VOID',qty=0,line_total=0,updated_at=NOW(6)
                     WHERE id=? AND order_id=? AND status='ACTIVE'");
    $n=0; foreach(array_keys($base) as $id){ $u->execute([$id,$order]); $n+=$u->rowCount(); }
    if($sent) self::kitchenCancel($p,$order,$sent);
    return $n;
 }
 /* ============================================================
    V199 — KITCHEN KO CANCEL BATAO.

    Pehle: KOT ke baad manager password se item hataya to bill se hat
    jata, magar kitchen screen par wohi item "NEW" pari rehti aur koi
    parchi nahi jati — kitchen woh cheez bana deta jo customer ne
    cancel kar di.

    Ab: (1) kitchen screen us item ko CANCELLED dikhati hai (OpsService
    kitchen tickets query line ki halat dekhti hai), aur (2) jis printer
    par us item ki KOT gayi thi, usi par cancel parchi.
    ============================================================ */
 private static function kitchenCancel(PDO $p,string $order,array $rows):void{
    try{
        $bill=self::billOf($p,$order);
        $tq=$p->prepare("SELECT kt.ticket_no,kt.printer_id,kt.station_code FROM kitchen_ticket_items kti
                           JOIN kitchen_tickets kt ON kt.id=kti.kitchen_ticket_id
                          WHERE kti.order_item_id=? ORDER BY kt.sent_at DESC LIMIT 1");
        $byPrinter=[];
        foreach($rows as $r){
            $tq->execute([$r['id']]); $t=$tq->fetch(PDO::FETCH_ASSOC);
            if(!$t || !$t['printer_id']) continue;
            $byPrinter[$t['printer_id']]['tickets'][$t['ticket_no']]=1;
            $byPrinter[$t['printer_id']]['lines'][]=rtrim(rtrim(number_format((float)$r['sent_qty'],3,'.',''),'0'),'.').' x '.$r['item_name_snapshot'].'  -- CANCELLED';
        }
        foreach($byPrinter as $pid=>$g){
            $payload="*** CANCEL ***\nBill #".$bill."  (".implode(', ',array_keys($g['tickets'])).")\n"
                    .implode("\n",$g['lines'])."\nMAT BANAYEIN";
            $p->prepare("INSERT INTO printer_jobs(id,tenant_id,site_id,printer_id,job_type,reference_type,reference_id,payload_text,status) VALUES(?,?,?,?, 'KOT','ORDER',?,?, 'PENDING')")
              ->execute([uuid(),tenant_id(),site_id(),$pid,$order,$payload]);
        }
    }catch(\Throwable $e){ /* parchi na bane to bhi bill ka kaam na ruke */ }
 }
 private static function upsertOrderItem(PDO $p,string $order,array $i):array{
    $mid=self::resolveMenuId($p,$i);
    $name=$i['name']??'';
    $note=(string)($i['note']??'');
    /* ============================================================
       V170 — KITCHEN NOTE BHI PEHCHAN KA HISSA HAI.

       POS par cart ki har line TEEN cheezon se banti hai: item, naam,
       aur kitchen note. Yani "Chicken Karahi (spicy)" aur "Chicken
       Karahi (mirch kam)" DO alag lines hain — jaisa hona chahiye,
       kyunke kitchen ko dono alag banani hain.

       Magar yahan talash sirf DO cheezon par thi — item aur naam.
       Note ka koi zikr nahi. Nateeja: us ek hi row par dusri line aa
       kar `qty=?` likh deti thi (jama nahi karti, BADAL deti thi).

       Cart mein 3 lines  ->  database mein 1 row
       Qty 3              ->  database mein 1

       Yani bill hold kar ke wapas kholne par items ghayab, aur jo
       bacha us ki ginti bhi kam. Aur yehi `upsertOrderItem` bill band
       karte waqt bhi chalti hai — to customer se bhi kam paise liye
       ja rahe thay.

       `COALESCE(kitchen_note,'')` is liye ke purane rows mein yeh
       khana NULL para ho sakta hai; khali note aur NULL ek hi cheez
       hain.
       ============================================================ */
    $q=$p->prepare("SELECT id,sent_qty FROM order_items
                     WHERE order_id=? AND menu_item_id=? AND item_name_snapshot=?
                       AND COALESCE(kitchen_note,'')=? AND status='ACTIVE'
                     LIMIT 1 FOR UPDATE");
    $q->execute([$order,$mid,$name,$note]);
    $old=$q->fetch();
    $qty=(float)$i['qty'];
    $rate=(float)($i['unit_price']??$i['unitPrice']??0);
    $m=$p->prepare("SELECT mi.name,mi.consumption_type,mi.direct_inventory_item_id,mi.direct_inventory_qty,r.id recipe_id,r.version_no,r.yield_qty FROM menu_items mi LEFT JOIN recipes r ON r.menu_item_id=mi.id AND r.is_current=1 WHERE mi.id=?");
    $m->execute([$mid]);$menu=$m->fetch();
    if(!$menu)throw new \RuntimeException('Menu item not found in database: '.$name);
    if($old){
        $p->prepare("UPDATE order_items SET qty=?,unit_price=?,line_total=?,kitchen_note=?,updated_at=NOW(6) WHERE id=?")->execute([$qty,$rate,$qty*$rate,$note,$old['id']]);
        $id=$old['id'];
    }else{
        $id=uuid();$snap=null;
        if($menu['recipe_id']){
            $iq=$p->prepare("SELECT inventory_item_id,qty_per_yield,waste_pct FROM recipe_ingredients WHERE recipe_id=?");
            $iq->execute([$menu['recipe_id']]);$snap=$iq->fetchAll();
        }
        /* V72 — kis device/user ne yeh item daali. Ek hi table par kai order
           takers kaam karte hain; iske baghair yeh jaanne ka koi rasta nahi
           ke kis ne kya punch kiya. Column purane DB par na ho to insert
           bina uske chalta rahe — migration ke intezar mein POS nahi ruk sakta. */
        $dev=(string)($_SESSION['device_id']??'');$uid=current_user()['id']??null;
        try{
            $p->prepare("INSERT INTO order_items(id,tenant_id,site_id,order_id,menu_item_id,item_name_snapshot,qty,sent_qty,unit_price,line_total,kitchen_note,recipe_id_snapshot,recipe_version_snapshot,recipe_snapshot_json,device_id,created_by_user_id,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ACTIVE')")->execute([$id,tenant_id(),site_id(),$order,$mid,$name,$qty,0,$rate,$qty*$rate,$note,$menu['recipe_id']?:null,$menu['version_no']?:null,$snap?json_encode($snap):null,$dev?:null,$uid]);
        }catch(\Throwable $e){
            $p->prepare("INSERT INTO order_items(id,tenant_id,site_id,order_id,menu_item_id,item_name_snapshot,qty,sent_qty,unit_price,line_total,kitchen_note,recipe_id_snapshot,recipe_version_snapshot,recipe_snapshot_json,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ACTIVE')")->execute([$id,tenant_id(),site_id(),$order,$mid,$name,$qty,0,$rate,$qty*$rate,$note,$menu['recipe_id']?:null,$menu['version_no']?:null,$snap?json_encode($snap):null]);
        }
    }
    $row=$p->prepare("SELECT * FROM order_items WHERE id=?");$row->execute([$id]);return$row->fetch();
 }
 private static function sendPendingInsideTx(PDO $p,string $order):void{$q=$p->prepare("SELECT oi.*,mi.category_id FROM order_items oi JOIN menu_items mi ON mi.id=oi.menu_item_id WHERE oi.order_id=? AND oi.qty>oi.sent_qty AND oi.status='ACTIVE'");$q->execute([$order]);$rows=$q->fetchAll();$groups=[];foreach($rows as $r){$pr=self::printerForMenu($p,$r['menu_item_id']);if(!$pr)continue;$groups[$pr['id']]['printer']=$pr;$groups[$pr['id']]['rows'][]=$r;}foreach($groups as $g){$kt=uuid();$ticket='KOT-'.strtoupper(substr(str_replace('-','',$kt),0,8));$p->prepare("INSERT INTO kitchen_tickets(id,tenant_id,site_id,order_id,ticket_no,printer_id,station_code,ticket_status,sent_at,created_by_user_id) VALUES(?,?,?,?,?,?,?,'NEW',NOW(6),?)")->execute([$kt,tenant_id(),site_id(),$order,$ticket,$g['printer']['id'],$g['printer']['station_code'],current_user()['id']??null]);$lines=[];foreach($g['rows'] as $r){$pending=(float)$r['qty']-(float)$r['sent_qty'];$p->prepare("INSERT INTO kitchen_ticket_items(id,kitchen_ticket_id,order_item_id,qty_sent,item_status,note_snapshot) VALUES(?,?,?,?, 'NEW',?)")->execute([uuid(),$kt,$r['id'],$pending,$r['kitchen_note']]);$p->prepare("UPDATE order_items SET sent_qty=qty WHERE id=?")->execute([$r['id']]);$lines[]=$pending.' x '.$r['item_name_snapshot'];}$p->prepare("INSERT INTO printer_jobs(id,tenant_id,site_id,printer_id,job_type,reference_type,reference_id,payload_text,status) VALUES(?,?,?,?, 'KOT','KITCHEN_TICKET',?,?, 'PENDING')")->execute([uuid(),tenant_id(),site_id(),$g['printer']['id'],$kt,$ticket."\n".implode("\n",$lines)]);}}
 private static function postInventory(PDO $p,string $order,string $bill,array $items):void{$loc=self::defaultLocation($p);$moves=[];foreach($items as $i){$mid=self::resolveMenuId($p,$i);$m=$p->prepare("SELECT mi.consumption_type,mi.direct_inventory_item_id,mi.direct_inventory_qty,r.id recipe_id,r.yield_qty FROM menu_items mi LEFT JOIN recipes r ON r.menu_item_id=mi.id AND r.is_current=1 WHERE mi.id=?");$m->execute([$mid]);$x=$m->fetch();if(!$x)continue;$qty=(float)$i['qty'];$oi=$p->prepare("SELECT id FROM order_items WHERE order_id=? AND menu_item_id=? AND item_name_snapshot=? LIMIT 1");$oi->execute([$order,$mid,$i['name']]);$oid=$oi->fetchColumn();if($x['consumption_type']==='DIRECT_INVENTORY'&&$x['direct_inventory_item_id'])$moves[]=(object)['item_id'=>$x['direct_inventory_item_id'],'location_id'=>$loc,'qty'=>-$qty*(float)$x['direct_inventory_qty'],'unit_cost'=>self::cost($p,$x['direct_inventory_item_id']),'source_order_item_id'=>$oid];elseif($x['consumption_type']==='RECIPE'&&$x['recipe_id']){$ri=$p->prepare("SELECT inventory_item_id,qty_per_yield,waste_pct FROM recipe_ingredients WHERE recipe_id=?");$ri->execute([$x['recipe_id']]);foreach($ri->fetchAll() as $r){$need=((float)$r['qty_per_yield']*$qty/max(.000001,(float)$x['yield_qty']))*(1+(float)$r['waste_pct']/100);$moves[]=(object)['item_id'=>$r['inventory_item_id'],'location_id'=>$loc,'qty'=>-$need,'unit_cost'=>self::cost($p,$r['inventory_item_id']),'source_order_item_id'=>$oid];}}}if($moves)$moves=self::liveMoves($p,$moves,$bill);
 if($moves)InventoryService::postMovement($p,'SALE','ORDER',$order,$bill,$moves,current_user()['id']??null);}
 /* ============================================================
    V172 — TOOTI HUI STOCK WIRING BILL KO NA ROKE.

    Yeh error asal customer par aaya, Complete Payment dabate hi:

        SQLSTATE[23000] 1452 ... stock_transaction_lines,
        CONSTRAINT fk_stl_item ... REFERENCES inventory_items(id)

    Matlab: kisi menu item ki recipe (ya us ka direct inventory link)
    aise inventory item ki taraf ishara kar rahi thi jo us database
    mein MOJOOD HI NAHI. Stock ki line likhte waqt database ne mana
    kar diya, poora transaction ulat gaya, aur BILL BAND HI NA HUA —
    customer counter par khara reh gaya.

    Aisa kaise hota hai: recipe_ingredients par FK hai, to ek hi
    database ke andar yeh ban nahi sakta. Magar cloud par rows SYNC
    se aati hain — agar bachcha row (recipe ki line) pohanch jaye aur
    us ka baap (inventory item) kisi wajah se chhoot jaye, to cloud
    par yehi soorat ban jati hai.

    FAISLA: stock ka hisaab ahem hai, magar GAHAK KI ADAYEGI ZYADA
    ahem hai. Ab aisi line chup chaap chhanat di jati hai aur error
    log mein likh di jati hai (malik ko nazar aaye aur wo recipe
    theek kar le), bill band ho jata hai. Pehle ulta tha: stock bhi
    nahi lagta tha AUR bill bhi nahi banta tha.

    `deleted_at` yahan nahi dekha jata — band kiya hua inventory item
    bhi mojood hota hai aur us ka stock katna chahiye. Sirf yeh dekha
    jata hai ke row hai ya nahi.
    ============================================================ */
 private static function liveMoves(PDO $p,array $moves,string $bill):array{
    $ids=[]; foreach($moves as $m){ $i=(string)($m->item_id??''); if($i!=='')$ids[$i]=1; }
    if(!$ids)return [];
    $in=implode(',',array_fill(0,count($ids),'?'));
    $q=$p->prepare("SELECT id FROM inventory_items WHERE id IN ($in)");
    $q->execute(array_keys($ids));
    $live=[]; foreach($q->fetchAll(\PDO::FETCH_COLUMN) as $id)$live[(string)$id]=1;
    $keep=[]; $lost=[];
    foreach($moves as $m){
        $i=(string)($m->item_id??'');
        if($i!==''&&isset($live[$i]))$keep[]=$m; else $lost[$i]=1;
    }
    if($lost){
        try{ ErrorLog::op('stock/missing-item',
             'Bill '.$bill.': stock not deducted for '.count($lost).' ingredient(s) - '
            .'the inventory item no longer exists. Check the recipe for the items on this bill. IDs: '
            .implode(', ',array_slice(array_keys($lost),0,5)), 'ERROR'); }catch(\Throwable $e){}
    }
    return $keep;
 }
  /**
  * Is item ka kitchen printer.
  *
  * V94 — pehle SIRF routing table dekhi jati thi, aur `sendKot()` mein
  * `if(!$pr) continue;` tha. Nateeja: agar malik ne category->printer
  * routing set nahi ki (naya business, ya demo), to KOI item kitchen
  * nahi jata tha, `sent_qty` sifar reh jata, aur kisi ko batata bhi
  * koi nahi — order khamoshi se gum ho jata tha.
  *
  * Ab teen darje:
  *   1. Is category ki apni routing
  *   2. Koi bhi KITCHEN printer
  *   3. Koi bhi chalu printer
  * Kaghaz kahin to nikle — us se behtar hai ke order gum ho jaye.
  */
 private static function printerForMenu(PDO $p,string $mid):?array{
    $q=$p->prepare("SELECT p.id,p.name,p.station_code
                      FROM menu_items mi
                      JOIN menu_category_printer_routes r ON r.category_id=mi.category_id AND r.is_active=1
                      JOIN printers p ON p.id=r.printer_id AND p.is_active=1
                     WHERE mi.id=? ORDER BY r.is_primary DESC,r.route_priority LIMIT 1");
    $q->execute([$mid]);
    if($row=$q->fetch())return $row;

    $q2=$p->prepare("SELECT id,name,station_code FROM printers
                      WHERE site_id=? AND is_active=1
                      ORDER BY FIELD(printer_type,'KITCHEN','BAR','RECEIPT'), is_default DESC, name
                      LIMIT 1");
    $q2->execute([site_id()]);
    return $q2->fetch()?:null;
 }
 /* V146 — ab ek hi qanoon, InventoryService mein. Dekhen wahan ka note. */
 private static function defaultLocation(PDO $p):string{return InventoryService::defaultLocationId($p);}
 private static function cost(PDO $p,string $id):float{$q=$p->prepare("SELECT avg_cost_per_stock_unit FROM inventory_items WHERE id=?");$q->execute([$id]);return(float)$q->fetchColumn();}
 private static function tableId(PDO $p,string $name):?string{if(!$name)return null;$q=$p->prepare("SELECT id FROM dining_tables WHERE site_id=? AND display_name=? LIMIT 1");$q->execute([site_id(),$name]);return$q->fetchColumn()?:null;}
 private static function modeCode(string $m):string{$m=strtoupper(str_replace(' ','_',trim($m)));return $m==='HOME_DELIVERY'?'DELIVERY':($m==='DINE_IN'?'DINE_IN':($m==='TAKEAWAY'?'TAKEAWAY':'DELIVERY'));}
 /* ============================================================
    V171 — DELIVERY CHARGE SIRF HOME DELIVERY PAR.

    Do pehre, dono zaroori:

    1) BILL KA TYPE. Yahan `modeCode()` ISTEMAL NAHI karte — wo ek
       anjaan mode ko bhi 'DELIVERY' bana deta hai (us ka default
       yehi hai). Agar us par bharosa karte to kisi din ek ajeeb
       mode aata aur Dine In bill par delivery charge lag jata.
       Is liye seedha naam dekhte hain: sirf HOME_DELIVERY.

    2) RAQAM KABHI MANFI NA HO. Manfi charge bill ghata deta — wahi
       raasta jis se V168 wala -500 ka bill bana tha.
    ============================================================ */
 private static function deliveryOf(array $d):float{
    $m=strtoupper(str_replace(' ','_',trim((string)($d['service_mode']??''))));
    if($m!=='HOME_DELIVERY'&&$m!=='DELIVERY')return 0.0;
    return max(0.0,(float)($d['delivery_charge']??0));
 }
 private static function paymentCode(string $m):string{$x=strtoupper(str_replace([' ','/'],'_',trim($m)));return ['BANK_TRANSFER'=>'BANK','SPLIT_PAYMENT'=>'CASH'][$x]??$x;}
 private static function queueSync(PDO $p,string $table,string $id,string $op):void{$q=$p->prepare("SELECT id FROM sync_nodes WHERE tenant_id=? AND site_id=? AND node_type='EDGE' AND status='ACTIVE' LIMIT 1");$q->execute([tenant_id(),site_id()]);$node=$q->fetchColumn();if(!$node)return;$p->prepare("INSERT INTO sync_outbox(id,tenant_id,site_id,source_node_id,entity_table,entity_id,operation_type,payload_json,idempotency_key,status) VALUES(?,?,?,?,?,?,?,?,?,'PENDING')")->execute([uuid(),tenant_id(),site_id(),$node,$table,$id,$op,json_encode(['id'=>$id]),uuid()]);}
}

// build: V199 build 2026-11-17

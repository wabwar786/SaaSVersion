<?php
namespace Aio\Services;
use Aio\DB;use PDO;
final class PosService {
 /* V203 — is request mein kitni lines ki price menu se durust ki gayi. */
 public static int $repriced=0;
 /* V204 — kitchen ja chuki qty kam / line hatane ki manzoori (api.php ne
    manager password transaction se BAHAR parkha ho to us manager ka naam). */
 public static ?string $voidMgr=null;
 public static array $voidInfo=['reason'=>'','note'=>''];
 /* ============================================================
    V204 — KITCHEN JA CHUKA ITEM: MANZOORI SERVER PAR, VOID LOG BHI.
    Pehle manager password sirf POS screen mangti thi; API se bina
    password Biryani (KOT ho chuki) hat gayi, aur void log POS screen
    khud likhti thi (jhoota "approved by Owner" bhi ban jata, asli mit
    bhi jata). Ab: manager khud login ho, ya request ke saath manager
    password (lock wala) — warna inkaar. Log server likhta hai.
    ============================================================ */
 private static function voidApprove(PDO $p,string $order,string $item,float $qty):void{
    if($qty<=0.0001) return;
    $u=current_user()??[];
    $by=\Aio\Auth::isManager() ? (string)($u['name']??$u['full_name']??'Manager') : (string)(self::$voidMgr??'');
    if($by==='') throw new PosConflict('VOID_APPROVAL','"'.$item.'" kitchen ja chuka hai — kam karne / hatane ke liye manager password chahiye.');
    $bill=self::billOf($p,$order);
    $rec=['bill'=>$bill,'item'=>$item,'qty'=>$qty,'reason'=>(string)(self::$voidInfo['reason']?:'—'),'note'=>(string)self::$voidInfo['note'],
          'approvedBy'=>$by,'by'=>(string)($u['name']??$u['full_name']??''),'at'=>date('d/m/Y, h:i A'),'server'=>1,'order_id'=>$order];
    $p->prepare("INSERT INTO ui_records(id,tenant_id,site_id,module_key,data_json,origin_node) VALUES(?,?,?,?,?,?)")
      ->execute([uuid(),tenant_id(),site_id(),'void_logs',json_encode($rec,JSON_UNESCAPED_UNICODE),(string)cfg('app.role')]);
    try{ Audit::log('ITEM_VOID','pos',['id'=>$order,'label'=>'Bill '.$bill.': '.$qty.' x '.$item.' (approved by '.$by.')']); }catch(\Throwable $e){}
 }
 /* ============================================================
    V203 — TAX KA RATE SERVER KI SETTINGS SE.
    Pehle jo rate / tax POS bhejta wahi save hota (test: tax -200 bheja,
    400 ka bill 200 ka ban gaya). Ab rate sirf Settings ka: Card / Raast
    / Easypaisa / JazzCash / Bank / Online = card ka rate, baqi cash ka.
    Hold par (abhi payment nahi chuni) POS cash ya card ka rate bhejta
    hai — inhi do mein se ho to woh, warna cash.
    ============================================================ */
 private static function taxRateFor(array $d,bool $final):float{
    $t=SettingsService::taxes(); $cash=(float)$t['tax_cash']; $card=(float)$t['tax_card'];
    if($final){
        $pm=(string)($d['payment_code']??'Cash');
        return preg_match('/card|raast|easypaisa|jazzcash|bank|online/i',$pm)?$card:$cash;
    }
    $r=(float)($d['tax_rate']??$cash);
    return abs($r-$card)<0.001?$card:$cash;
 }
 /* ============================================================
    V203 — DISCOUNT % SIRF WOHI JO RAQAM SE MILTA HO.
    Pehle POS jo discount_pct bhejta wahi save hota. Raqam 0 aur % 90
    bhej do -> had ka check (raqam par) pass, aur baad mein tablet ke
    item jorne par server wahi 90% laga deta. Ab % tabhi save hota hai
    jab woh discount ki raqam se milta ho.
    ============================================================ */
 private static function cleanPct(array $d,float $base,float $disc):float{
    $pc=(float)($d['discount_pct']??0);
    if($pc<=0 || $base<=0 || $disc<=0) return 0.0;
    return abs($base*$pc/100-$disc)<=1.0 ? min(100.0,$pc) : 0.0;
 }
 /* ============================================================
    V203 — ITEM KI PRICE AUR NAAM MENU SE.
    Pehle line ki price POS se aati aur bina dekhe save hoti (test: 400
    ki Biryani 1 rupay mein bik gayi) — discount ki had ka koi faida na
    rehta. Ab jaiz price sirf: menu ki price, us item ke kisi variant ki
    price, ya is bill par pehle se likhi us line ki price (menu ki price
    beech mein badli ho). Kuch aur aaye to menu / variant ki price lagti
    hai aur Audit log mein PRICE_CORRECTED.
    Naam bhi: menu ke naam se shuru na ho to menu ka naam (kitchen ko
    ghalat naam na jaye).
    ============================================================ */
 /* V203 — qty 0 ya manfi wali line bill par nahi aati. Pehle Biryani x2
    ke saath Biryani x-1 bheji to bill 400 ka ban jata (800 - 400).
    0 / manfi line ka matlab "line hatao" — woh kaam dropRemoved karta
    hai (pehle se save line VOID). */
 private static function posItems(array $items):array{
    return array_values(array_filter($items,function($i){ return is_array($i) && (float)($i['qty']??0) > 0; }));
 }
 private static function fairName(PDO $p,string $mid,string $name):string{
    $q=$p->prepare("SELECT name FROM menu_items WHERE id=?");$q->execute([$mid]);$base=(string)$q->fetchColumn();
    if($base==='') return $name;
    return (strpos($name,$base)===0)?$name:$base;
 }
 private static function fairRate(PDO $p,string $mid,string $name,float $rate,?float $oldRate):float{
    $q=$p->prepare("SELECT name,base_price FROM menu_items WHERE id=?");$q->execute([$mid]);$m=$q->fetch(PDO::FETCH_ASSOC);
    if(!$m) return $rate;
    $ok=[(float)$m['base_price']]; $byName=[];
    $vq=$p->prepare("SELECT name,price FROM menu_item_variants WHERE menu_item_id=? AND is_active=1 AND deleted_at IS NULL");
    $vq->execute([$mid]);
    foreach($vq->fetchAll(PDO::FETCH_ASSOC) as $v){ $ok[]=(float)$v['price']; $byName[strtolower(trim($v['name']))]=(float)$v['price']; }
    if($oldRate!==null) $ok[]=$oldRate;
    foreach($ok as $o) if(abs($o-$rate)<0.005) return $rate;
    $want=(float)$m['base_price'];
    if(preg_match('/\(([^()]+)\)\s*$/',$name,$mm) && isset($byName[strtolower(trim($mm[1]))])) $want=$byName[strtolower(trim($mm[1]))];
    self::$repriced++;
    try{ Audit::log('PRICE_CORRECTED','pos',['id'=>$mid,'label'=>$name.': '.$rate.' -> '.$want]); }catch(\Throwable $e){}
    return $want;
 }

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
                /* V203 — sirf APNA khula account. Pehle kisi doosre cashier ka
                   shift_id bhej kar bill us ke galle mein dala ja sakta tha. */
                $q = $p->prepare("SELECT id FROM cashier_shifts
                                   WHERE id=? AND site_id=? AND status='OPEN' AND cashier_user_id=?
                                     AND deleted_at IS NULL LIMIT 1");
                $q->execute([$given, site_id(), current_user()['id'] ?? null]);
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
    /* ============================================================
       V200 — TABLET / QR ITEM JORE TO COUNTER KA DISCOUNT NA MITE.
       Tablet sirf items bhejta hai — discount, service, tax nahi. Pehle
       yahan "na bheja = 0" maana jata: counter ne table bill par 10%
       discount + service + 16% tax lagaya, waiter ne tablet se Naan
       jori -> discount, service aur tax sab 0 (test: 2,710 -> 2,980).
       Ab jo khana request mein hai hi nahi, wo bill par jo pehle se
       likha hai wahi rehta hai. POS counter yeh sab hamesha bhejta hai.
       ============================================================ */
    if(array_key_exists('tax_rate',$d)) $d['__tax_from_client']=1;   /* V203 */
    if(array_key_exists('discount_amount',$d)){   /* V202 — counter ka discount: had se zyada ho to manager */
        $dq=min(max(0.0,(float)$d['discount_amount']),max(0.0,$sub+(float)($d['service_charge']??0)));
        self::discAllowed($p,$order,$d,$sub,$dq);
    }
    if(!array_key_exists('discount_amount',$d) || !array_key_exists('service_charge',$d)
       || !array_key_exists('tax_rate',$d) || !array_key_exists('delivery_charge',$d)){
        $oq=$p->prepare("SELECT subtotal,discount_amount,discount_pct,service_charge,tax_amount,delivery_charge
                           FROM orders WHERE id=?");
        $oq->execute([$order]); $o=$oq->fetch(PDO::FETCH_ASSOC) ?: [];
        $pct=(float)($o['discount_pct']??0);
        if(!array_key_exists('discount_amount',$d)){
            $d['discount_pct']=$pct;
            $d['discount_amount']=$pct>0 ? round(min($sub*$pct/100,$sub),2) : (float)($o['discount_amount']??0);
        }
        if(!array_key_exists('service_charge',$d)) $d['service_charge']=(float)($o['service_charge']??0);
        if(!array_key_exists('delivery_charge',$d)) $d['delivery_charge']=(float)($o['delivery_charge']??0);
        if(!array_key_exists('tax_rate',$d)){
            $pt=(float)($o['tax_amount']??0);
            $pb=(float)($o['subtotal']??0)-(float)($o['discount_amount']??0)+(float)($o['service_charge']??0);
            $r=0.0;
            if($pt>0 && $pb>0){
                $r = \Aio\Services\TaxMode::isInclusive() ? ($pt/max(0.01,$pb-$pt))*100 : ($pt/$pb)*100;
                $r = round($r,2);
            }
            $d['tax_rate']=$r;
        }
    }
    $disc=(float)($d['discount_amount']??0);
    $svc=(float)($d['service_charge']??0);
    /* V199 — 400 ke bill par 1,000 discount: bill 0 theek tha, magar
       discount 1,000 save hota aur discount reports 600 zyada dikhati.
       Ab discount zyada se zyada bill (items + service) jitna. */
    $svc=max(0.0,$svc);   /* V203 — service charge manfi nahi */
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
    if (!empty($d['__tax_from_client'])) $rate = self::taxRateFor($d,false);   /* V203 */
    if ($rate > 0) {
        $sp  = \Aio\Services\TaxMode::split($taxable, $rate);
        $tax = $sp['tax'];
        $grand = \Aio\Services\TaxMode::isInclusive() ? $taxable : ($taxable + $tax);
    } else {
        /* V203 — rate 0 = tax 0 (POS ki bheji tax raqam nahi). */
        $tax   = 0.0;
        $grand = $taxable;
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
    $dpct=self::cleanPct($d,$sub,$disc);   /* V203 */
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
    foreach(self::posItems($items) as $i){ $row=self::upsertOrderItem($p,$order,$i); $keep[]=(string)$row['id']; $n++; }
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

 public static function sendKot(array $d,array $items): array { return self::txRetry(function(PDO $p)use($d,$items){self::lockSite($p);/* V197 */$order=self::ensureOpenOrder($p,$d);$pending=[];$keep=[];foreach(self::posItems($items) as $i){$row=self::upsertOrderItem($p,$order,$i);$i['name']=$row['item_name_snapshot'];/* V203 */$keep[]=(string)$row['id'];$pd=max(0,(float)$row['qty']-(float)$row['sent_qty']);if($pd>0)$pending[]=['row'=>$row,'pending'=>$pd,'input'=>$i];}
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
 public static function finalize(array $d,array $items): string { return self::txRetry(function(PDO $p)use($d,$items){self::lockSite($p);/* V197 */$order=self::ensureOpenOrder($p,$d);$subtotal=0;$keep=[];$inv=[];foreach(self::posItems($items) as $i){$row=self::upsertOrderItem($p,$order,$i);$i['name']=$row['item_name_snapshot'];$inv[]=$i;/* V203 */$keep[]=(string)$row['id'];$subtotal+=(float)$row['qty']*(float)$row['unit_price'];}self::dropRemoved($p,$order,$d,$keep);/* V196 */self::bumpRev($p,$order);/* V197 */self::moveToToday($p,$order,$d);/* V203 */$discount=(float)($d['discount_amount']??0);$service=max(0.0,(float)($d['service_charge']??0));/* V203 manfi nahi */$discount=min(max(0.0,$discount),max(0.0,$subtotal+$service));/* V199 — discount bill se bara save na ho */self::discAllowed($p,$order,$d,$subtotal,$discount);/* V202 *//* ============================================================
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
        $rate    = self::taxRateFor($d,true);   /* V203 — Settings se */
        $sp      = \Aio\Services\TaxMode::split($taxable, $rate);
        $inclusive = \Aio\Services\TaxMode::isInclusive();
        $tax     = $sp['tax'];
        /* Purana POS rate nahi bhejta — us soorat mein uska apna tax lo,
           warna wo build achanak bina tax ke bill banane lagta. */
        if ($rate <= 0) { $tax = 0.0; $inclusive = false; }   /* V203 */
        $grand = $inclusive ? $taxable : ($taxable + $tax);
        /* V171 — DELIVERY CHARGE TAX KE BAAD. `hold()` mein bhi bilkul
           yehi lakeer, bilkul isi jagah hai. Dono ek jaise na hon to
           hold ka total aur bill ka total alag ho jayen — V168 wala
           masla, jis ki shikayat "discount theek nahi" ki shakal mein
           aati thi. */
        $delivery = self::deliveryOf($d);
        $grand    = $grand + $delivery;$received=(float)($d['received_amount']??$grand);$p->prepare("UPDATE orders SET order_status='CLOSED',payment_status='PAID',subtotal=?,discount_amount=?,discount_pct=?,service_charge=?,delivery_charge=?,tax_amount=?,grand_total=?,paid_amount=?,change_amount=?,hold_status=0,closed_at=NOW(6),updated_at=NOW(6) WHERE id=?")->execute([$subtotal,$discount,self::cleanPct($d,$subtotal,$discount),$service,$delivery,$tax,$grand,$grand,max(0,$received-$grand),$order]);$exists=$p->prepare("SELECT COUNT(*) FROM payments WHERE order_id=? AND status='COMPLETED'");$exists->execute([$order]);if(!(int)$exists->fetchColumn()){$pm=$p->prepare("SELECT id FROM payment_methods WHERE site_id=? AND code=? AND is_active=1 LIMIT 1");$pm->execute([site_id(),self::paymentCode($d['payment_code']??'Cash')]);$pmid=$pm->fetchColumn();if(!$pmid)throw new \RuntimeException('Payment method not configured');$p->prepare("INSERT INTO payments(id,tenant_id,site_id,order_id,shift_id,payment_method_id,amount,received_amount,change_amount,status,paid_at,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,'COMPLETED',NOW(6),?)")->execute([uuid(),tenant_id(),site_id(),$order,self::shiftFor($p,$d),$pmid,$grand,$received,max(0,$received-$grand),current_user()['id']??null]);}
  $st=$p->prepare("SELECT COUNT(*) FROM stock_transactions WHERE reference_type='ORDER' AND reference_id=? AND transaction_type='SALE'");$st->execute([$order]);if(!(int)$st->fetchColumn()){self::postInventory($p,$order,$d['bill_no'],$inv);}self::sendPendingInsideTx($p,$order);self::queueSync($p,'orders',$order,'UPDATE');return $order;}); }
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
                /* V200 — tablet ne table KHAALI dekha tha (`table_fresh`), magar
                   beech mein doosre tablet / QR ne us par bill khol diya. Is
                   tablet ki qty us bill ki qty ko mita deti (1+1 = 1). Rok kar
                   tablet ko taaza bill dikhao. */
                if (!empty($d['table_fresh'])) {
                    $bq=$p->prepare("SELECT bill_no FROM orders WHERE id=?"); $bq->execute([$openId]);
                    throw new PosConflict('CHANGED','Is table par abhi bill khul chuka hai — taaza bill dekh kar dobara bhejein.',
                                          (string)$bq->fetchColumn(), (string)$openId);
                }
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
    /* ============================================================
       V202 — NAYE BILL KA NUMBER HAMESHA SERVER DETA HAI.
       POS raat bhar khula raha: screen par kal ka agla number (#0142)
       tha, aur 12 baje ke baad pehla bill #0142 hi ban gaya — aaj ke bill
       0142 se chale, #0001-#0141 kabhi bane hi nahi (numbering mein gap).
       Ab naya bill aaj ka agla number leta hai (branch lock ke andar),
       chahe screen par kuch bhi likha ho. POS jawab se asli number le
       leta hai (syncBill) — KOT / bill par wohi chhapta hai.
       ============================================================ */
    $bill = PageData::billPrefix().str_pad((string)PageData::nextBill(),4,'0',STR_PAD_LEFT);
    $id=null;
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
    /* V201 — EK TABLE, EK BILL. Do counters ne ek saath khali table chuna,
       ya POS ne purani fehrist dekhi — pehle dono ke alag bill us table par
       ban jate (test: T-05 par 3 khule bill). Ab naya bill us table par
       nahi banta jahan bill pehle se khula ho; POS apne items usi bill
       mein daal deta hai (TABLE_BUSY). */
    if($table) self::tableFree($p,(string)$table,null,'TABLE_BUSY');
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
    /* V201 — "Change Table" se bill aise table par nahi ja sakta jahan doosra bill khula ho. */
    if($table){
        $cq=$p->prepare("SELECT table_id FROM orders WHERE id=?"); $cq->execute([$id]);
        if((string)$cq->fetchColumn()!==(string)$table) self::tableFree($p,(string)$table,$id,'TABLE_BUSY_MOVE');   /* sirf jab table BADAL raha ho */
    }
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
 private static function resolveMenuId(PDO $p,array $i,?string $order=null):string{
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
    /* ============================================================
       V200 — MENU SE HATA ITEM, MAGAR BILL MEIN PEHLE SE HAI.
       Bill hold par tha (Lassi x2), manager ne menu se Lassi delete kar
       di -> bill charge hi nahi hota tha ("Menu item not found"). Customer
       kha chuka, bill phans gaya. Ab: jo item ISI bill mein pehle se hai
       (chahe menu se hat chuka ho) wo chalta hai. Naye bill mein hata hua
       item ab bhi nahi aa sakta.
       ============================================================ */
    if($order!==null){
        $q=$p->prepare("SELECT oi.menu_item_id FROM order_items oi
                         WHERE oi.order_id=? AND (oi.menu_item_id=? OR oi.item_name_snapshot=?) LIMIT 1");
        $q->execute([$order,$raw,(string)($i['name']??$base)]);
        if($id=$q->fetchColumn())return (string)$id;
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
 /* ============================================================
    V200 — QR / APP ORDER SEEDHA APNE BILL MEIN.

    Pehle "Accept" QR ke items cashier ke KHULE cart mein daal deta
    tha — cashier walk-in ka takeaway bana raha ho to table T-02 ke
    items usi walk-in ke bill mein jur jate, aur table ka naam bhi
    gayab (test: Biryani x1 + Naan x3, Takeaway, table khali).

    Ab server khud:
      - table ka order: us table ka khula bill ho to usi mein JORTA hai
        (qty jama hoti hai), warna table ka naya hold bill banata hai;
      - app ka order (table nahi): customer ke naam/phone ke saath naya
        hold bill.
    Cashier ka cart chheda hi nahi jata. Discount / service / tax wahi
    rehte hain jo bill par pehle se hain (dekho writeTotals).
    Ek order do dafa accept nahi ho sakta (PENDING ki shart, lock ke saath).
    ============================================================ */
 public static function qrAccept(string $qrId): array { return self::txRetry(function(PDO $p)use($qrId){
    self::lockSite($p);
    $q=$p->prepare("SELECT o.*, s.guest_name, s.guest_phone FROM qr_orders o
                      LEFT JOIN qr_sessions s ON s.id=o.session_id
                     WHERE o.id=? AND o.site_id=? FOR UPDATE");
    $q->execute([$qrId,site_id()]); $qo=$q->fetch(PDO::FETCH_ASSOC);
    if(!$qo) throw new \RuntimeException('This order was not found.');
    if($qo['status']!=='PENDING') throw new \RuntimeException('This order was already '.strtolower((string)$qo['status']).'.');
    $items=json_decode((string)$qo['items_json'],true)?:[];
    $table=trim((string)($qo['table_name']??''));
    $order=null;
    if($table!==''){
        $tid=self::tableId($p,$table);
        if($tid){
            $tq=$p->prepare("SELECT id FROM orders WHERE site_id=? AND table_id=? AND order_status='OPEN' ORDER BY created_at DESC LIMIT 1");
            $tq->execute([site_id(),$tid]); $order=$tq->fetchColumn()?:null;
        }
    }
    $d=['service_mode'=>$table!==''?'Dine In':'Takeaway','table_name'=>$table,
        'customer_id'=>'walkin','customer_name'=>(string)($qo['guest_name']??''),
        'bill_no'=>PageData::billPrefix().str_pad((string)PageData::nextBill(),4,'0',STR_PAD_LEFT)];
    if(!$order){
        $order=self::ensureOpenOrder($p,$d);
        if($table==='' && ((string)($qo['guest_name']??'')!=='' || (string)($qo['guest_phone']??'')!=='')){
            $note=' [App: '.trim((string)$qo['guest_name'].' '.(string)$qo['guest_phone']).']';
            $p->prepare("UPDATE orders SET notes=CONCAT(COALESCE(notes,''),?) WHERE id=?")->execute([$note,$order]);
        }
    }
    $added=0; $skipped=[];
    $ex=$p->prepare("SELECT qty,unit_price,sent_qty FROM order_items WHERE order_id=? AND menu_item_id=? AND item_name_snapshot=?
                       AND COALESCE(kitchen_note,'')=? AND status='ACTIVE' LIMIT 1");
    foreach($items as $it){
        $qty=(float)($it['qty']??0); if($qty<=0) continue;
        $line=['menu_item_id'=>(string)($it['id']??''),'name'=>(string)($it['name']??''),'base_name'=>(string)($it['name']??''),
               'unit_price'=>(float)($it['price']??0),'note'=>(string)($it['note']??''),'qty'=>$qty];
        try{
            $mid=self::resolveMenuId($p,$line,$order);
            $ex->execute([$order,$mid,$line['name'],$line['note']]);
            if($old=$ex->fetch(PDO::FETCH_ASSOC)){ $line['qty']=(float)$old['qty']+$qty; $line['unit_price']=(float)$old['unit_price']; }
            self::upsertOrderItem($p,$order,$line); $added++;
        }catch(\Throwable $e){ $skipped[]=$line['name']; }
    }
    if(!$added) throw new \RuntimeException('None of these items are on the menu any more: '.implode(', ',$skipped));
    /* Pehle se chalta (KOT wala) bill apni halat mein rahe; naya QR bill "hold". */
    $hq=$p->prepare("SELECT hold_status, (SELECT COUNT(*) FROM order_items WHERE order_id=orders.id AND sent_qty>0) FROM orders WHERE id=?");
    $hq->execute([$order]); $hs=$hq->fetch(PDO::FETCH_NUM);
    self::writeTotals($p,$order,['service_mode'=>$d['service_mode']],((int)$hs[1]>0)?(int)$hs[0]:1);
    $rev=self::bumpRev($p,$order);
    $p->prepare("UPDATE qr_orders SET status='ACCEPTED',handled_at=NOW(6),handled_by=? WHERE id=?")
      ->execute([current_user()['id']??null,$qrId]);
    self::queueSync($p,'orders',$order,'UPDATE');
    return ['order_id'=>$order,'bill_no'=>self::billOf($p,$order),'table'=>$table,'added'=>$added,'skipped'=>$skipped,'rev'=>$rev];
 }); }
 /** V201 — table par (is bill ke ilawa) koi khula bill ho to PosConflict. */
 private static function tableFree(PDO $p,string $tableId,?string $exceptOrder,string $kind):void{
    $q=$p->prepare("SELECT o.id,o.bill_no,dt.display_name FROM orders o JOIN dining_tables dt ON dt.id=o.table_id
                     WHERE o.site_id=? AND o.table_id=? AND o.order_status='OPEN' AND o.id<>? ORDER BY o.created_at LIMIT 1");
    $q->execute([site_id(),$tableId,(string)($exceptOrder??'')]);
    if($o=$q->fetch(PDO::FETCH_ASSOC)){
        throw new PosConflict($kind, $kind==='TABLE_BUSY'
            ? $o['display_name'].' par pehle se bill #'.$o['bill_no'].' khula hai — aap ke items usi bill mein daal diye gaye hain. Check kar ke dobara bhejein / charge karein.'
            : $o['display_name'].' par bill #'.$o['bill_no'].' khula hai — yeh bill wahan nahi ja sakta. Doosra table chunein.',
            (string)$o['bill_no'],(string)$o['id']);
    }
 }
 /** V201 — poore bill ka cancel (hold cancel): kitchen ja chuke items ki parchi. */
 public static function cancelSlipForOrder(string $order):void{
    try{
        $p=DB::pdo();
        $q=$p->prepare("SELECT id,item_name_snapshot,sent_qty FROM order_items WHERE order_id=? AND status='ACTIVE' AND sent_qty>0");
        $q->execute([$order]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if($rows) self::kitchenCancel($p,$order,$rows);
    }catch(\Throwable $e){}
 }
 /* ============================================================
    V202 — DISCOUNT KI HAD.
    Pehle koi had nahi thi: cashier 100% discount de deta (400 -> 0),
    koi password nahi. Ab Settings ki had (default 10%) tak cashier khud;
    us se zyada ke liye manager ka password (POS mangta hai). Manager /
    owner par had nahi. Chhoot:
      - customer ka apna tay-shuda discount (customer profile),
      - jo discount is bill par pehle hi manzoor ho kar save ho chuka.
    ============================================================ */
 private static function discAllowed(PDO $p,?string $order,array $d,float $base,float $disc):void{
    if($disc<=0.009 || $base<=0) return;
    if(\Aio\Auth::isManager()) return;
    $pct=$disc/$base*100;
    $max=(float)(SettingsService::taxes()['max_discount'] ?? 10);
    if($pct <= $max+0.01) return;
    $cid=(string)($d['customer_id']??'');
    if($cid!=='' && preg_match('/^[0-9a-f-]{36}$/i',$cid)){
        $cq=$p->prepare("SELECT COALESCE(discount_pct,0) FROM customers WHERE id=? AND tenant_id=?");
        $cq->execute([$cid,tenant_id()]);
        if($pct <= (float)$cq->fetchColumn()+0.01) return;
    }
    /* V203 — pehle se manzoor discount: % ke hisaab se. V202 mein raqam
       dekhi jati thi: 4 items par Rs 800 (50%) manzoor, phir bill 2 items
       ka aur Rs 800 wahi -> bill muft. Ab bill chhota ho to manzoor % hi
       chalta hai, raqam nahi. */
    if($order){
        $oq=$p->prepare("SELECT subtotal,discount_amount FROM orders WHERE id=?");
        $oq->execute([$order]); $o=$oq->fetch(PDO::FETCH_ASSOC);
        if($o && (float)$o['subtotal']>0 && (float)$o['discount_amount']>0){
            $had=(float)$o['discount_amount']/(float)$o['subtotal']*100;
            if($pct <= $had+0.01) return;
        }
    }
    /* Manager ka password api.php mein (transaction se bahar) parkha jata
       hai — ghalat koshishein ginti mein rahen aur 5 ke baad taala lage. */
    $mgr=(string)($d['__disc_mgr']??'');
    if($mgr!==''){
        try{ Audit::log('DISCOUNT_APPROVED','pos',['id'=>$order,'label'=>round($pct,1).'% by '.$mgr]); }catch(\Throwable $e){}
        return;
    }
    throw new PosConflict('DISC_LIMIT','Discount '.round($pct,1).'% hai — cashier '.rtrim(rtrim(number_format($max,2,'.',''),'0'),'.').'% tak de sakta hai. Zyada ke liye manager password chahiye.');
 }
 /* ============================================================
    V203 — KAL KA HOLD BILL AAJ CHARGE HO TO AAJ KI SALE.
    Pehle bill kal ki tareekh par hi band hota: kal ka din band ho chuka,
    phir bhi us ki sale badal jati, aur aaj ki shift closing (2,305) aur
    aaj ki sale (1,905) mein farq. Ab charge ke waqt tareekh aaj ki aur
    aaj ka agla number; purana number Audit log mein.
    ============================================================ */
 private static function moveToToday(PDO $p,string $order,array &$d):void{
    $q=$p->prepare("SELECT business_date,bill_no FROM orders WHERE id=?");$q->execute([$order]);$o=$q->fetch(PDO::FETCH_ASSOC);
    if(!$o || (string)$o['business_date']>=today()) return;
    $nb=PageData::billPrefix().str_pad((string)PageData::nextBill(),4,'0',STR_PAD_LEFT);
    $p->prepare("UPDATE orders SET business_date=?, bill_no=?, updated_at=NOW(6) WHERE id=?")->execute([today(),$nb,$order]);
    try{ Audit::log('BILL_RENUMBERED','pos',['id'=>$order,'label'=>(string)$o['bill_no'].' ('.$o['business_date'].') -> '.$nb]); }catch(\Throwable $e){}
    $d['bill_no']=$nb;
 }
 /* ============================================================
    V203 — MANAGER PASSWORD: 5 GHALAT = 5 MINUTE TAALA.
    Pehle 40 ghalat koshishon ke baad bhi 41vi sahi chal jati — password
    andaze se nikala ja sakta tha. Ab har ghalat koshish Audit log mein
    (MGR_PW_FAIL); 5 minute mein 5 ghalat ho jayen to us user ke liye
    5 minute tak manager password qabool nahi. Transaction se bahar
    chalta hai taake ghalat koshish ka record mit na jaye.
    ============================================================ */
 public static function managerByPassword(string $pw,string $why=''):array{
    $p=DB::pdo(); $uid=current_user()['id']??'';
    $lq=$p->prepare("SELECT COUNT(*),MIN(created_at) FROM audit_log WHERE user_id=? AND action='MGR_PW_FAIL' AND created_at>NOW(6)-INTERVAL 5 MINUTE");
    $lq->execute([$uid]); $l=$lq->fetch(PDO::FETCH_NUM);
    if((int)$l[0]>=5) return ['ok'=>false,'locked'=>true,'message'=>'5 dafa ghalat manager password — 5 minute baad dobara koshish karein.'];
    if($pw==='') return ['ok'=>false,'message'=>'Manager password is required'];
    $mq=$p->prepare("SELECT DISTINCT u.id,u.full_name,u.password_hash FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id
                      WHERE u.tenant_id=? AND u.status='ACTIVE' AND u.deleted_at IS NULL
                        AND (u.is_tenant_admin=1 OR r.name LIKE '%Manager%' OR r.name LIKE '%Owner%' OR r.name LIKE '%Admin%')");
    $mq->execute([tenant_id()]);
    foreach($mq->fetchAll(PDO::FETCH_ASSOC) as $m){
        if($m['password_hash'] && password_verify($pw,$m['password_hash'])) return ['ok'=>true,'id'=>$m['id'],'manager'=>$m['full_name']];
    }
    try{ Audit::log('MGR_PW_FAIL','pos',['label'=>$why]); }catch(\Throwable $e){}
    $left=4-(int)$l[0];
    return ['ok'=>false,'message'=>'Incorrect manager password'.($left>0?' ('.$left.' koshish baqi)':' — 5 minute ka taala laga diya gaya.')];
 }
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
    foreach($sent as $r) self::voidApprove($p,$order,(string)$r['item_name_snapshot'],(float)$r['sent_qty']);   /* V204 */
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
            $fmt=fn($v)=>rtrim(rtrim(number_format((float)$v,3,'.',''),'0'),'.');
            $byPrinter[$t['printer_id']]['lines'][]=$fmt($r['sent_qty']).' x '.$r['item_name_snapshot'].'  -- CANCELLED'
                .(isset($r['of'])?(' ('.$fmt($r['of']).' mein se)'):'');
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
    $mid=self::resolveMenuId($p,$i,$order);
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
    $name=self::fairName($p,$mid,(string)$name);   /* V203 */
    $q=$p->prepare("SELECT id,sent_qty,unit_price FROM order_items
                     WHERE order_id=? AND menu_item_id=? AND item_name_snapshot=?
                       AND COALESCE(kitchen_note,'')=? AND status='ACTIVE'
                     LIMIT 1 FOR UPDATE");
    $q->execute([$order,$mid,$name,$note]);
    $old=$q->fetch();
    $qty=(float)$i['qty'];
    $rate=(float)($i['unit_price']??$i['unitPrice']??0);
    $rate=self::fairRate($p,$mid,(string)$name,$rate,$old?(float)$old['unit_price']:null);   /* V203 */
    $m=$p->prepare("SELECT mi.name,mi.consumption_type,mi.direct_inventory_item_id,mi.direct_inventory_qty,r.id recipe_id,r.version_no,r.yield_qty FROM menu_items mi LEFT JOIN recipes r ON r.menu_item_id=mi.id AND r.is_current=1 WHERE mi.id=?");
    $m->execute([$mid]);$menu=$m->fetch();
    if(!$menu)throw new \RuntimeException('Menu item not found in database: '.$name);
    if($old){
        $p->prepare("UPDATE order_items SET qty=?,unit_price=?,line_total=?,kitchen_note=?,updated_at=NOW(6) WHERE id=?")->execute([$qty,$rate,$qty*$rate,$note,$old['id']]);
        /* V201 — kitchen ja chuki qty KAM ki gayi (2 -> 1): kitchen ko parchi
           "1 x Karahi CANCELLED (2 mein se)". `sent_qty` bhi utni kar do,
           taake har save par parchi dobara na nikle; qty phir barhe to
           sirf nayi qty kitchen jati hai. (POS par is ke liye manager
           password lagta hai — line hatane jaisa.) */
        if((float)$old['sent_qty'] > $qty && $qty>0){
            $diff=(float)$old['sent_qty']-$qty;
            self::voidApprove($p,$order,(string)$name,$diff);   /* V204 */
            self::kitchenCancel($p,$order,[['id'=>$old['id'],'item_name_snapshot'=>$name,'sent_qty'=>$diff,'of'=>(float)$old['sent_qty']]]);
            $p->prepare("UPDATE order_items SET sent_qty=? WHERE id=?")->execute([$qty,$old['id']]);
        }
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
 private static function postInventory(PDO $p,string $order,string $bill,array $items):void{$loc=self::defaultLocation($p);$moves=[];foreach($items as $i){$mid=self::resolveMenuId($p,$i,$order);$m=$p->prepare("SELECT mi.consumption_type,mi.direct_inventory_item_id,mi.direct_inventory_qty,r.id recipe_id,r.yield_qty FROM menu_items mi LEFT JOIN recipes r ON r.menu_item_id=mi.id AND r.is_current=1 WHERE mi.id=?");$m->execute([$mid]);$x=$m->fetch();if(!$x)continue;$qty=(float)$i['qty'];$oi=$p->prepare("SELECT id FROM order_items WHERE order_id=? AND menu_item_id=? AND item_name_snapshot=? LIMIT 1");$oi->execute([$order,$mid,$i['name']]);$oid=$oi->fetchColumn();if($x['consumption_type']==='DIRECT_INVENTORY'&&$x['direct_inventory_item_id'])$moves[]=(object)['item_id'=>$x['direct_inventory_item_id'],'location_id'=>$loc,'qty'=>-$qty*(float)$x['direct_inventory_qty'],'unit_cost'=>self::cost($p,$x['direct_inventory_item_id']),'source_order_item_id'=>$oid];elseif($x['consumption_type']==='RECIPE'&&$x['recipe_id']){$ri=$p->prepare("SELECT inventory_item_id,qty_per_yield,waste_pct FROM recipe_ingredients WHERE recipe_id=?");$ri->execute([$x['recipe_id']]);foreach($ri->fetchAll() as $r){$need=((float)$r['qty_per_yield']*$qty/max(.000001,(float)$x['yield_qty']))*(1+(float)$r['waste_pct']/100);$moves[]=(object)['item_id'=>$r['inventory_item_id'],'location_id'=>$loc,'qty'=>-$need,'unit_cost'=>self::cost($p,$r['inventory_item_id']),'source_order_item_id'=>$oid];}}}if($moves)$moves=self::liveMoves($p,$moves,$bill);
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

// build: V204 build 2026-11-18

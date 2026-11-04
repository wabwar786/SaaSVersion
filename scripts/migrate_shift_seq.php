<?php
/**
 * migrate_shift_seq.php — ACCOUNT KA SEEDHA SADA NUMBER.  (V181)
 *
 * Ek column: `cashier_shifts.shift_seq`.
 *
 * MASLA
 * Account ka naam `S-261104-7C9F` hai — tareekh aur uuid ke chaar
 * harf. Malik ko yeh padhna parta hai, likhna parta hai, aur phone
 * par doosre ko batana parta hai. Us ne kaha: "account number 1, 2,
 * 3 mein hone chahiyen."
 *
 * PURANA NAAM KYUN NAHI BADLA JATA
 *     cashier_shifts par UNIQUE (site_id, shift_no)
 * Do offline PC ek hi waqt "5" bana dete to sync par takrao hota aur
 * ek account doosre ko mita deta. Is liye `shift_no` JAISA HAI WAISA
 * HI rehta hai — wohi sync par jata hai, wohi backup ki file ka naam
 * banta hai, wohi audit log mein likha jata hai.
 *
 * Yeh naya khana SIRF DIKHANE ke liye hai. Screen par `7` chhapta
 * hai, database mein `S-261104-7C9F` hi rehta hai.
 *
 * PURANI SHIFTS BHI GINTI MEIN AA JATI HAIN
 * Har site par alag alag, `opened_at` ki tarteeb se 1, 2, 3...  Is
 * liye purana data khali nahi rehta aur ginti beech se shuru nahi
 * hoti. Jo row kisi wajah se number na pa sake, us par purana poora
 * naam hi dikhta hai (`acct_show()` yehi karta hai) — kuch gum nahi
 * hota.
 *
 * Script idempotent hai: column pehle se ho to sirf un rows ko number
 * deti hai jo abhi tak khali hain. Jitni dafa chalao.
 *
 * SYNC: `Sync::columns()` information_schema se columns parhta hai, to
 * yeh column khud ba khud safar karne lagta hai — magar SIRF jab DONO
 * taraf mojood ho. Is liye CLOUD PEHLE deploy hota hai.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

function ss_table_exists(\PDO $p, string $t): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.tables
                       WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return (bool)$q->fetchColumn();
}
function ss_col_exists(\PDO $p, string $t, string $c): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM information_schema.columns
                       WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t, $c]); return (bool)$q->fetchColumn();
}

if (!ss_table_exists($pdo, 'cashier_shifts')) {
    echo "SHIFT_SEQ_SKIP table nahi hai\n";
    return;
}

$added = 0;
if (!ss_col_exists($pdo, 'cashier_shifts', 'shift_seq')) {
    try {
        $pdo->exec("ALTER TABLE `cashier_shifts` ADD COLUMN `shift_seq` INT NULL AFTER `shift_no`");
        $added = 1;
        echo "  + cashier_shifts.shift_seq\n";
    } catch (\Throwable $e) {
        /* Deploy kabhi nahi rokte. Number na bane to screen par purana
           poora naam dikhta rahe ga — kaam rukta nahi. */
        echo "  ! cashier_shifts.shift_seq: ".substr($e->getMessage(), 0, 140)."\n";
        echo "SHIFT_SEQ_OK added=0 numbered=0\n";
        return;
    }
}

/* ---- purani (aur koi bhi bina number wali) shifts ko ginti do ----
   Har site ki apni ginti. `opened_at` ki tarteeb — jo pehle khula
   wo 1. `id` sirf tab dekha jata hai jab do shifts ka waqt bilkul
   ek ho, taake har baar wohi natija aaye. */
$numbered = 0;
try {
    $sites = $pdo->query("SELECT DISTINCT site_id FROM cashier_shifts")->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($sites as $sid) {
        /* is site par abhi tak sab se bara number kya hai */
        $m = $pdo->prepare("SELECT COALESCE(MAX(shift_seq),0) FROM cashier_shifts WHERE site_id=?");
        $m->execute([$sid]);
        $next = (int)$m->fetchColumn() + 1;

        $q = $pdo->prepare("SELECT id FROM cashier_shifts
                             WHERE site_id=? AND shift_seq IS NULL
                             ORDER BY opened_at, id");
        $q->execute([$sid]);
        $rows = $q->fetchAll(\PDO::FETCH_COLUMN);
        if (!$rows) continue;

        $up = $pdo->prepare("UPDATE cashier_shifts SET shift_seq=? WHERE id=? AND shift_seq IS NULL");
        foreach ($rows as $id) {
            $up->execute([$next, $id]);
            $next++; $numbered++;
        }
    }
} catch (\Throwable $e) {
    echo "  ! ginti: ".substr($e->getMessage(), 0, 140)."\n";
}

echo "SHIFT_SEQ_OK added=$added numbered=$numbered\n";

// build: V181 build 2026-11-04

<?php
/**
 * clear_cloud_images.php — CLOUD DATABASE SE PURANI TASWEEREN SAAF.  (V159)
 *
 * ============================================================
 * YEH KYUN CHAHIYE
 * ============================================================
 * `menu_items.image_url` mein poori tasweer BASE64 ban kar baithti hai
 * (har item tak ~1MB). `menu_items` sync ki dono fehriston mein tha, is
 * liye V159 se pehle counter par lagayi hui HAR tasweer cloud database
 * mein chali jati thi — bina kisi faide ke, kyunke us tasweer ko cloud
 * par koi dekhta hi nahi tha.
 *
 * V159 se wo rasta band hai (Sync::changedRows). Magar jo pehle se
 * cloud par jama ho chuki hain wo wahin baithi hain aur backup, pull
 * aur hosting — teenon par bojh daal rahi hain. Yeh script unhein saaf
 * karti hai.
 *
 * ============================================================
 * EHTIYAT — SAB KUCH SAAF KARNA GHALAT HOGA
 * ============================================================
 * Cloud ki har tasweer fazool nahi: `is_online = 1` waale items ki
 * tasweeren QR table-menu aur customer ke mobile/web menu par NAZAR
 * AATI HAIN. Unhein mitana customer ko khali menu dikha dega.
 *
 * Is liye default scope `offline` hai: sirf wo tasweeren jati hain jo
 * online menu par nazar NAHI aatin — yani wohi jo counter se chadhi
 * theen. Malik ki apni lagayi hui online waali mehfooz rehti hain.
 *
 * Aur yeh script MIGRATION NAHI hai: `migration_order.php` mein jaan
 * boojh kar nahi daali. Har deploy par apne aap tasweeren mitana —
 * chahe wo malik ne khud online lagayi hon — qabool nahi.
 *
 * ============================================================
 * CHALANE KA TARIQA  (Railway ki shell mein)
 * ============================================================
 *   php scripts/clear_cloud_images.php
 *       -> SIRF RIPORT. Kuch nahi mitata. Pehle yeh chalayein.
 *
 *   php scripts/clear_cloud_images.php --apply
 *       -> online menu par nazar NA aane wali tasweeren mitati hai
 *
 *   php scripts/clear_cloud_images.php --apply --scope=all
 *       -> saari tasweeren mitati hai (QR / customer menu bhi khali
 *          ho jayega — sirf tab jab aap yehi chahte hon)
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

/* Local node par chalna be-maani hai: wahan ki tasweeren hi asal
   tasweeren hain, aur yeh script unhein mita degi. */
if (cfg('app.role') !== 'cloud') {
    echo "IMAGES_SKIP yeh script sirf cloud par chalti hai (app.role=cloud).\n";
    exit(0);
}

$argvv = $_SERVER['argv'] ?? [];
$apply = in_array('--apply', $argvv, true);
$scope = 'offline';
foreach ($argvv as $a) {
    if (strpos($a, '--scope=') === 0) $scope = substr($a, 8);
}
if (!in_array($scope, ['offline', 'all'], true)) {
    echo "IMAGES_FAIL --scope sirf 'offline' ya 'all' ho sakta hai.\n";
    exit(1);
}

$pdo = DB::pdo();

$colq = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                        WHERE table_schema=DATABASE() AND table_name='menu_items'
                          AND column_name='image_url'");
$colq->execute();
if (!$colq->fetchColumn()) {
    echo "IMAGES_SKIP menu_items.image_url column maujood hi nahi.\n";
    exit(0);
}

/* Sirf wo rows jin mein waqai tasweer hai — `http...` wale link halke
   hain, unhein chhorna hi behtar hai. Bojh base64 ka hai. */
$isData = "image_url IS NOT NULL AND image_url <> '' AND image_url LIKE 'data:image/%'";

$sum = function (string $extra) use ($pdo, $isData): array {
    $q = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(LENGTH(image_url)),0) b
                        FROM menu_items WHERE $isData $extra");
    $r = $q->fetch(\PDO::FETCH_ASSOC) ?: ['n' => 0, 'b' => 0];
    return ['n' => (int)$r['n'], 'mb' => round(((float)$r['b']) / 1048576, 2)];
};

$all     = $sum('');
$online  = $sum("AND is_online = 1 AND deleted_at IS NULL");
$offline = $sum("AND NOT (is_online = 1 AND deleted_at IS NULL)");

echo "\n";
echo "  CLOUD PAR ABHI (sirf base64 tasweeren)\n";
echo "  ----------------------------------------------\n";
printf("  Kul                        %5d items  %8.2f MB\n", $all['n'], $all['mb']);
printf("  Online menu par nazar aati %5d items  %8.2f MB  <- QR / customer menu\n", $online['n'], $online['mb']);
printf("  Online par nazar NAHI aati %5d items  %8.2f MB  <- counter se chadhi huin\n", $offline['n'], $offline['mb']);
echo "\n";

$where = $scope === 'all' ? '' : "AND NOT (is_online = 1 AND deleted_at IS NULL)";
$target = $scope === 'all' ? $all : $offline;

if (!$apply) {
    printf("  RIPORT ONLY - kuch nahi mitaya gaya.\n");
    printf("  --apply dene par %d items se %.2f MB hat jayega (scope=%s).\n", $target['n'], $target['mb'], $scope);
    echo "\nIMAGES_REPORT items=".$target['n']." mb=".$target['mb']." scope=$scope applied=0\n";
    exit(0);
}

if ($target['n'] === 0) {
    echo "IMAGES_OK mitane ke liye kuch hai hi nahi. items=0 mb=0 scope=$scope applied=1\n";
    exit(0);
}

/* Thore thore kar ke — ek hi UPDATE mein hazaron MB chhune se replica
   lag bhi sakta hai aur binlog phool jata hai. */
$done = 0;
try {
    while (true) {
        $n = $pdo->exec("UPDATE menu_items SET image_url = NULL,
                                updated_at = updated_at   /* watermark na chhero */
                          WHERE $isData $where LIMIT 200");
        if (!$n) break;
        $done += $n;
        echo "  ... $done\n";
    }
} catch (\Throwable $e) {
    echo "  ! ".substr($e->getMessage(), 0, 160)."\n";
}

$after = $sum('');
printf("\n  Ab cloud par: %d items, %.2f MB (pehle %d / %.2f MB)\n",
       $after['n'], $after['mb'], $all['n'], $all['mb']);
echo "IMAGES_OK items=$done mb=".$target['mb']." scope=$scope applied=1\n";

// build: V159 build 2026-10-20

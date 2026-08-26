<?php
/**
 * reset_super_admin.php — Platform Console ka login wapas.
 *
 * `Platform::ensureSuperUser()` sirf tab account banata hai jab
 * `platform_users` mein ek bhi SUPER row NA ho. Agar row maujood hai
 * magar password bhool gaya ho (ya `sa-password-change` se badal chuka
 * ho), to koi rasta bacha hi nahi tha — `superLogin()` sirf
 * "Invalid platform credentials" kehta hai aur bas.
 *
 * Chalane ka tareeqa (Railway shell, ya kisi bhi jagah repo root se):
 *
 *   php scripts/reset_super_admin.php                       # list dikhata hai
 *   php scripts/reset_super_admin.php --email=you@x.com --password='NayaPass@123'
 *   php scripts/reset_super_admin.php --email=you@x.com --password='X' --create
 *
 * `--create` naya SUPER account banata hai agar us email ka koi na ho.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) $args[strtolower($m[1])] = $m[2] ?? '1';
}
$email = trim((string)($args['email'] ?? ''));
$pass  = (string)($args['password'] ?? '');
$create = isset($args['create']);
$once   = isset($args['once']);   // boot ke liye: ek hi dafa lagta hai

$pdo = DB::pdo();

$rows = $pdo->query("SELECT id,email,full_name,role,status FROM platform_users ORDER BY role,email")->fetchAll();
echo "Platform accounts (" . count($rows) . "):\n";
foreach ($rows as $r) {
    printf("  %-32s %-8s %-10s %s\n", $r['email'], $r['role'], $r['status'], $r['full_name']);
}
echo str_repeat('-', 62) . "\n";

if ($email === '' || $pass === '') {
    echo "Password reset karne ke liye:\n";
    echo "  php scripts/reset_super_admin.php --email=\"<email>\" --password=\"<naya password>\"\n";
    echo "Naya account banane ke liye isi command ke saath --create lagayein.\n";
    return;
}
if (strlen($pass) < 8) { echo "ERROR: password kam az kam 8 characters ka hona chahiye.\n"; return; }

/* ------------------------------------------------------------------
   --once : DEPLOY PAR PASSWORD DOBARA DOBARA SET NA HO.

   Pehle entrypoint har boot par yeh reset chala deta tha. Matlab jab
   tak SUPER_ADMIN_* variables Railway mein pade rehte, HAR upload par
   password wapas usi par chala jata tha — chahe user ne beech mein
   Account screen se badal liya ho. Deploy ko data (khaas kar
   credentials) kabhi nahi chhoona chahiye.

   Ab ek marker rakha jata hai. Wahi email+password dobara aaye to
   kuch nahi hota. Value badlein to phir se lag jata hai (yehi maqsad
   hai). Marker mein password NahI, sirf uska hash jata hai.
   ------------------------------------------------------------------ */
if ($once) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS boot_markers (
            marker_key  VARCHAR(64)  NOT NULL PRIMARY KEY,
            marker_val  VARCHAR(190) NOT NULL,
            applied_at  DATETIME(6)  NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $fp = hash('sha256', strtolower(trim($email)) . '|' . $pass);
        $q  = $pdo->prepare("SELECT marker_val FROM boot_markers WHERE marker_key='super_admin_reset'");
        $q->execute();
        $seen = (string)($q->fetchColumn() ?: '');

        if ($seen === $fp) {
            echo "SKIPPED: yeh reset pehle hi lag chuka hai - password ko haath nahi lagaya.\n";
            echo "         (SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD variables ab hata dein.)\n";
            return;
        }
        $pdo->prepare("INSERT INTO boot_markers(marker_key,marker_val,applied_at)
                       VALUES('super_admin_reset',?,NOW(6))
                       ON DUPLICATE KEY UPDATE marker_val=VALUES(marker_val), applied_at=NOW(6)")
            ->execute([$fp]);
    } catch (\Throwable $e) {
        /* Marker na bane to reset bhi na karein - warna wahi purana
           masla ke har deploy par password badalta rahe. */
        echo "SKIPPED: boot marker nahi likha ja saka (" . substr($e->getMessage(), 0, 90) . ")\n";
        return;
    }
}

$q = $pdo->prepare("SELECT id FROM platform_users WHERE LOWER(email)=LOWER(?) LIMIT 1");
$q->execute([$email]);
$id = $q->fetchColumn();

$hash = password_hash($pass, PASSWORD_DEFAULT);

if ($id) {
    /* status bhi ACTIVE karo - superLogin() sirf ACTIVE rows dekhta hai,
       is liye SUSPENDED account bhi bilkul "ghalat password" jaisa lagta hai. */
    $pdo->prepare("UPDATE platform_users SET password_hash=?, status='ACTIVE', role='SUPER' WHERE id=?")
        ->execute([$hash, $id]);
    echo "OK: $email ka password badal diya gaya (status ACTIVE, role SUPER).\n";
} elseif ($create) {
    $pdo->prepare("INSERT INTO platform_users(id,role,full_name,email,password_hash,status)
                   VALUES(?,'SUPER','Platform Owner',?,?,'ACTIVE')")
        ->execute([uuid(), $email, $hash]);
    echo "OK: naya SUPER account bana diya gaya: $email\n";
} else {
    echo "ERROR: '$email' ka koi account nahi mila. Banane ke liye --create lagayein.\n";
    return;
}

echo "Ab Platform Console par isi email/password se login karein.\n";

// build: V62.3 build 2026-08-26

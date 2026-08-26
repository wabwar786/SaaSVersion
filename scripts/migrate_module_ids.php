<?php
/**
 * migrate_module_ids.php — "0 Modules" WALE BUG KA ASAL ILAJ.
 *
 * Masla:
 *   `platform_modules.id` `uuid()` se banti thi — har installation par
 *   RANDOM. Cloud par `pos` ka id kuch aur, branch computer par kuch aur.
 *   Aur `user_module_access.module_id` aur `role_modules.module_id` usi
 *   id par join karte hain (`Auth::moduleKeys()`).
 *
 *   Nateeja: node par assign kiye hue modules cloud tak pohanch bhi jayen
 *   to unka module_id wahan kisi module se match nahi karta tha. Join
 *   khali -> user ko **"0 Modules"** dikhta tha. Na error, na warning.
 *
 * Yeh migration har module ka id `module_uuid(module_key)` par le aati
 * hai — jo har installation par bilkul wahi nikalta hai. Cloud par bhi
 * chalti hai aur har node par bhi; uske baad dono taraf ke ids ek jaise
 * ho jate hain aur permissions waqai sync hone lagti hain.
 *
 * Child references PEHLE update hote hain, phir parent ka id — warna
 * orphan rows reh jayen aur permissions phir bhi khali dikhein.
 *
 * Idempotent. Baar baar chal sakti hai.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

$tableExists = function (string $t) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$t]); return (bool)$q->fetchColumn();
};
$colExists = function (string $t, string $c) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) AS n FROM information_schema.columns
                         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t, $c]); return (bool)$q->fetchColumn();
};

if (!$tableExists('platform_modules')) {
    echo "MODULE_IDS_SKIPPED platform_modules missing\n";
    return;
}

/* Jin tables mein module_id hai — inhe pehle theek karna hai. */
$children = [];
foreach (['user_module_access', 'role_modules', 'site_modules', 'tenant_modules'] as $t) {
    if ($tableExists($t) && $colExists($t, 'module_id')) $children[] = $t;
}

$rows = $pdo->query("SELECT id, module_key FROM platform_modules")->fetchAll();
$moved = 0; $already = 0; $merged = 0; $failed = 0;

/* Canonical id kis module_key ka kya hai */
$canon = [];
foreach ($rows as $r) $canon[(string)$r['module_key']] = module_uuid((string)$r['module_key']);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($rows as $r) {
    $old = (string)$r['id'];
    $key = (string)$r['module_key'];
    $new = $canon[$key];
    if ($old === $new) { $already++; continue; }

    try {
        $pdo->beginTransaction();

        /* Kya canonical id pehle se maujood hai (aadha migrate hua tha)?
           Aisi soorat mein purani row ko usi mein merge kar do. */
        $ex = $pdo->prepare("SELECT COUNT(*) FROM platform_modules WHERE id=?");
        $ex->execute([$new]);
        $collide = (int)$ex->fetchColumn() > 0;

        foreach ($children as $t) {
            $u = $pdo->prepare("UPDATE `$t` SET module_id=? WHERE module_id=?");
            $u->execute([$new, $old]);
        }

        if ($collide) {
            $pdo->prepare("DELETE FROM platform_modules WHERE id=?")->execute([$old]);
            $merged++;
        } else {
            $pdo->prepare("UPDATE platform_modules SET id=? WHERE id=?")->execute([$new, $old]);
            $moved++;
        }

        $pdo->commit();
        echo "  ~ $key  $old -> $new" . ($collide ? '  (merged)' : '') . "\n";
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $failed++;
        echo "  ! $key: " . substr($e->getMessage(), 0, 100) . "\n";
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

/* Duplicate rows saaf: agar kisi key ke do entries reh gaye hon */
try {
    $pdo->exec("DELETE pm FROM platform_modules pm
                JOIN platform_modules keep
                  ON keep.module_key = pm.module_key AND keep.id < pm.id
                 WHERE pm.id <> keep.id AND pm.id NOT IN
                       (SELECT module_id FROM (SELECT DISTINCT module_id FROM role_modules) x)");
} catch (\Throwable $e) {}

/* Ab in tables ki rows dobara sync honi chahiyen — watermark reset */
try {
    $pdo->prepare("DELETE FROM sync_state WHERE scope IN (?,?,?,?)")
        ->execute(['push:user_module_access', 'pull:user_module_access',
                   'push:role_modules', 'pull:role_modules']);
} catch (\Throwable $e) {}

/* Fingerprint — cloud aur node par yeh HAMESHA barabar hona chahiye.
   Sync handshake isi ko compare karta hai. */
$fp = $pdo->query("SELECT MD5(GROUP_CONCAT(CONCAT(module_key,':',id) ORDER BY module_key SEPARATOR '|')) AS fp
                     FROM platform_modules WHERE is_active=1")->fetchColumn();

echo "MODULE_IDS_READY moved=$moved merged=$merged already_ok=$already failed=$failed\n";
echo "MODULE_FINGERPRINT $fp\n";

// build: V62.2 build 2026-08-26

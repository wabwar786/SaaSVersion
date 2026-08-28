<?php
/**
 * migrate_role_scope.php — mojooda Cashier/Waiter/Kitchen users se
 * dashboard aur baqi management modules WAPAS le lo.
 *
 * `seed_roles.php` sirf naye installations par chalta hai. Jo customers
 * pehle se chal rahe hain un ke cashiers ke paas abhi bhi dashboard hai
 * — aur wahan se poore branch ki sale nazar aati hai.
 *
 * Yeh sirf UN roles ko chhoota hai jo system ke apne banaye hue hain
 * (Cashier / Waiter / Chef). Malik ne khud koi role banaya ya badla ho
 * to us ko haath nahi lagta.
 *
 * Idempotent.
 */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;

$pdo = DB::pdo();

$allow = [
    'Cashier'       => ['shift','pos','closing'],
    'Waiter'        => ['tablet','tables'],
    'Chef / Kitchen'=> ['kds'],
];

$mods = [];
try {
    foreach ($pdo->query("SELECT id, module_key FROM platform_modules")->fetchAll() as $r) {
        $mods[(string)$r['module_key']] = (string)$r['id'];
    }
} catch (\Throwable $e) { echo "ROLE_SCOPE_SKIPPED platform_modules missing\n"; return; }

$changed = 0;
foreach ($allow as $roleName => $keys) {
    $q = $pdo->prepare("SELECT id, tenant_id FROM roles WHERE name=?");
    $q->execute([$roleName]);
    foreach ($q->fetchAll() as $role) {
        $keep = [];
        foreach ($keys as $k) if (isset($mods[$k])) $keep[] = $mods[$k];
        if (!$keep) continue;

        $in = implode(',', array_fill(0, count($keep), '?'));
        try {
            /* Role se ziada ke modules hata do */
            $d = $pdo->prepare("DELETE FROM role_modules WHERE role_id=? AND module_id NOT IN ($in)");
            $d->execute(array_merge([$role['id']], $keep));
            $removed = $d->rowCount();

            /* Jo chahiyen wo add karo */
            $added = 0;
            foreach ($keep as $mid) {
                $c = $pdo->prepare("SELECT COUNT(*) FROM role_modules WHERE role_id=? AND module_id=?");
                $c->execute([$role['id'], $mid]);
                if ((int)$c->fetchColumn() === 0) {
                    $pdo->prepare("INSERT INTO role_modules(id,role_id,module_id,is_allowed) VALUES(?,?,?,1)")
                        ->execute([uuid(), $role['id'], $mid]);
                    $added++;
                }
            }

            /* Aur seedhe user par diye hue extra modules bhi — warna
               role theek hone ke bawajood user ko dashboard dikhta rahega. */
            $u = $pdo->prepare("DELETE uma FROM user_module_access uma
                                 JOIN user_roles ur ON ur.user_id = uma.user_id AND ur.role_id = ?
                                WHERE uma.module_id NOT IN ($in)");
            $u->execute(array_merge([$role['id']], $keep));

            if ($removed || $added || $u->rowCount()) {
                printf("  ~ %-16s removed=%d added=%d user-level=%d\n",
                       $roleName, $removed, $added, $u->rowCount());
                $changed++;
            }
        } catch (\Throwable $e) {
            echo "  ! $roleName: ".substr($e->getMessage(), 0, 100)."\n";
        }
    }
}
echo "ROLE_SCOPE_READY roles_changed=$changed\n";

// build: V78 build 2026-08-28

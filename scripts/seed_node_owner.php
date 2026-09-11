<?php
/**
 * NODE OWNER SEED
 *
 * MASLA JO YEH HAL KARTA HAI:
 * Offline node ka database install ke waqt khali banta hai. Users cloud
 * se **sync** ke zariye aate the. Agar sync na chali (aur ek arse tak
 * nahi chalti thi — launcher ka redirect bug), to node par ek bhi user
 * nahi hota tha. Customer wahi password daalta jo portal par chalta hai
 * aur node "Invalid login" keh deta — dono databases ka data alag hota
 * tha aur alamat bilkul gumraah karne wali thi.
 *
 * Ab package banate waqt us business ke owner ka login (email, naam aur
 * password ka HASH — plaintext kabhi nahi) sealed config mein chala jata
 * hai. Yeh script pehle boot par usay local database mein daal deti hai.
 *
 * Nateeja: node pehle din se wahi login qubool karta hai jo portal par
 * chalta hai — sync se pehle bhi.
 *
 * Idempotent: user pehle se ho to kuch nahi karta.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Aio\DB;

$owner = $GLOBALS['config']['owner'] ?? null;
if (!\is_array($owner) || empty($owner['email']) || empty($owner['password_hash'])) {
    echo "NODE_OWNER_SKIPPED package mein owner login nahi hai\n";
    return;
}

$pdo = DB::pdo();
$tid = tenant_id();

try {
    $q = $pdo->prepare(
        "SELECT id FROM users
          WHERE tenant_id=? AND (LOWER(email)=LOWER(?) OR LOWER(username)=LOWER(?))
          LIMIT 1");
    $q->execute([$tid, (string)$owner['email'], (string)($owner['username'] ?? $owner['email'])]);
    $existing = $q->fetchColumn();

    if ($existing) {
        /* Pehle se maujood hai. Password sirf tab lagao jab local hash
           khali ho — warna jo password node par baad mein set kiya gaya
           ho wo mit jayega. */
        $h = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
        $h->execute([$existing]);
        if (!\trim((string)$h->fetchColumn())) {
            $pdo->prepare("UPDATE users SET password_hash=?, status='ACTIVE' WHERE id=?")
                ->execute([(string)$owner['password_hash'], $existing]);
            echo "NODE_OWNER_PASSWORD_RESTORED\n";
        } else {
            echo "NODE_OWNER_ALREADY_PRESENT\n";
        }
        return;
    }

    $pdo->prepare(
        "INSERT INTO users(id,tenant_id,username,email,full_name,password_hash,password_algo,
                           status,is_tenant_admin,created_at,updated_at)
         VALUES(?,?,?,?,?,?, 'BCRYPT','ACTIVE',1,NOW(6),NOW(6))")
        ->execute([
            (string)($owner['id'] ?? \uuid()),
            $tid,
            (string)($owner['username'] ?? \explode('@', (string)$owner['email'])[0]),
            (string)$owner['email'],
            (string)($owner['full_name'] ?? 'Owner'),
            (string)$owner['password_hash'],
        ]);

    /* Owner ko admin role bhi mil jaye taake sidebar bhara hua ho. */
    try {
        $r = $pdo->prepare("SELECT id FROM roles WHERE tenant_id=? AND name LIKE 'Owner%' LIMIT 1");
        $r->execute([$tid]);
        if ($rid = $r->fetchColumn()) {
            $uq = $pdo->prepare("SELECT id FROM users WHERE tenant_id=? AND LOWER(email)=LOWER(?) LIMIT 1");
            $uq->execute([$tid, (string)$owner['email']]);
            $uid = (string)$uq->fetchColumn();
            if ($uid) {
                $pdo->prepare("INSERT IGNORE INTO user_roles(id,user_id,role_id) VALUES(?,?,?)")
                    ->execute([\uuid(), $uid, $rid]);
            }
        }
    } catch (\Throwable $e) { /* role na mile to bhi login chalta rahe */ }

    echo "NODE_OWNER_CREATED " . $owner['email'] . "\n";
} catch (\Throwable $e) {
    echo "NODE_OWNER_FAILED " . $e->getMessage() . "\n";
}

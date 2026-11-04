<?php
declare(strict_types=1);

use Aio\Auth;
use Aio\Csrf;

function cfg(string $path, mixed $default=null): mixed {
    $v = $GLOBALS['config'] ?? [];
    foreach (explode('.', $path) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) return $default;
        $v = $v[$part];
    }
    return $v;
}
function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function uuid(): string {
    $d=random_bytes(16); $d[6]=chr((ord($d[6]) & 0x0f)|0x40); $d[8]=chr((ord($d[8]) & 0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4));
}
/**
 * module_uuid() — HAR INSTALLATION PAR WAHI ID.
 *
 * Pehle `platform_modules.id` `uuid()` se banti thi — yani cloud par
 * `pos` module ka id kuch aur, branch computer par kuch aur. Aur
 * `user_module_access.module_id` / `role_modules.module_id` usi id par
 * join karte hain.
 *
 * Nateeja: node par assign kiye hue modules cloud par pohanch bhi jayen
 * to unka `module_id` wahan kisi module se match hi nahi karta tha —
 * join khali, aur user ko **"0 Modules"** dikhta tha. Koi error nahi,
 * koi warning nahi. Poora khamosh.
 *
 * Ab id `module_key` se derive hoti hai (UUIDv5 jaisa, namespace ke
 * saath md5), is liye har installation par bilkul wahi nikalti hai.
 */
function module_uuid(string $key): string {
    $h = md5('aio-platform-module:' . strtolower(trim($key)));
    // UUID shakal + version 5 / variant bits, taake CHAR(36) columns fit rahe
    $b = str_split($h, 2);
    $b[6] = dechex((hexdec($b[6]) & 0x0f) | 0x50);
    $b[8] = dechex((hexdec($b[8]) & 0x3f) | 0x80);
    $h = implode('', array_map(fn($x) => str_pad($x, 2, '0', STR_PAD_LEFT), $b));
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
}

/**
 * setting_uuid() — HAR NODE PAR WAHI ID (site + group + key se).
 *
 * `site_settings` par UNIQUE (site_id, setting_group, setting_key) laga
 * hua hai, magar `id` ab tak `uuid()` se banti thi — yani cloud par
 * `tax/mode` ki row ka id kuch aur, branch computer par kuch aur.
 *
 * Sync upsert JAAN-BOOJH KAR id ke hisaab se chalta hai (V-pehle ka
 * bug: doosri unique key takraane par purani row overwrite ho jati thi,
 * do bills mil kar ek ban jate the). Is liye alag id wali row sync par
 * CONFLICT ban kar REJECT hoti hai — settings kabhi neeche aati hi
 * nahi.
 *
 * Ab id derive hoti hai, is liye dono taraf bilkul wahi nikalti hai aur
 * upsert sahi row par lagta hai.
 */
function setting_uuid(string $siteId, string $group, string $key): string {
    $h = md5('aio-site-setting:' . strtolower(trim($siteId)) . '|' .
             strtolower(trim($group)) . '|' . strtolower(trim($key)));
    $b = str_split($h, 2);
    $b[6] = dechex((hexdec($b[6]) & 0x0f) | 0x50);
    $b[8] = dechex((hexdec($b[8]) & 0x3f) | 0x80);
    $h = implode('', array_map(fn($x) => str_pad($x, 2, '0', STR_PAD_LEFT), $b));
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
}

function tenant_id(): string {
    // Cloud (multi-tenant): logged-in user's tenant, or the tenant resolved
    // from the business slug during login. Local (offline): fixed config tenant.
    if (cfg('app.role') === 'cloud') {
        if (!empty($_SESSION['user']['tenant_id'])) return (string)$_SESSION['user']['tenant_id'];
        if (!empty($_SESSION['login_tenant_id']))  return (string)$_SESSION['login_tenant_id'];
    }
    return (string)cfg('app.tenant_id');
}
function site_id(): string {
    if (cfg('app.role') === 'cloud') {
        if (!empty($_SESSION['site_id'])) return (string)$_SESSION['site_id'];
        if (!empty($_SESSION['user']['tenant_id'])) {
            try {
                $q = \Aio\DB::pdo()->prepare("SELECT id FROM sites WHERE tenant_id=? AND deleted_at IS NULL ORDER BY created_at LIMIT 1");
                $q->execute([$_SESSION['user']['tenant_id']]);
                $sid = $q->fetchColumn();
                if ($sid) { $_SESSION['site_id'] = $sid; return (string)$sid; }
            } catch (\Throwable $e) {}
        }
    }
    return (string)cfg('app.site_id');
}
function today(): string { return date('Y-m-d'); }
function money(float|int|string|null $n): string { return 'PKR '.number_format((float)$n, 2); }
function redirect(string $url): never { header('Location: '.$url); exit; }
function flash(string $key, ?string $value=null): ?string {
    if ($value !== null) { $_SESSION['_flash'][$key]=$value; return null; }
    $v=$_SESSION['_flash'][$key]??null; unset($_SESSION['_flash'][$key]); return $v;
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.e(Csrf::token()).'">'; }
function require_post_csrf(): void { if ($_SERVER['REQUEST_METHOD']==='POST') Csrf::verifyOrFail($_POST['_csrf']??''); }
function current_user(): ?array { return Auth::user(); }
function require_login(): void { Auth::requireLogin(); }
function require_module(string $module): void { Auth::requireModule($module); }
function json_response(array $data,int $status=200): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($data,JSON_UNESCAPED_UNICODE); exit; }

// build: V17.1 build 2026-08-25

/**
 * V84 — command line ke flags, SEALED build mein bhi.
 *
 * Sealed offline build scripts ko `php -r "...require sealed://..."` se
 * chalati hai, aur us soorat mein `$argv` maujood HI NAHI hota. Har
 * script apne tor par `$argv` parh rahi thi, is liye offline setup
 * "Undefined variable $argv" par ruk jata tha.
 *
 * Ab sab yahan se maangti hain. `$argv` na ho to khali array — script
 * apne default par chal padti hai (jo boot ke liye bilkul theek hai).
 *
 * @return array<string,string>  ['tenant'=>'abc','download'=>'1']
 */
function cli_args(): array
{
    $argv = $GLOBALS['argv'] ?? ($_SERVER['argv'] ?? null);
    if (!is_array($argv)) return [];

    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-zA-Z][a-zA-Z0-9_-]*)(?:=(.*))?$/', (string)$a, $m)) {
            $out[strtolower($m[1])] = $m[2] ?? '1';
        }
    }
    return $out;
}

/** Ek flag ki value (ya default). */
function cli_arg(string $name, string $default = ''): string
{
    $a = cli_args();
    return (string)($a[strtolower($name)] ?? $default);
}

/* ============================================================
   V181 — SCREEN PAR NUMBER SEEDHA SADA: 1, 2, 3.

   Malik ki baat: "bill number aur account number 1,2,3 mein hone
   chahiyen, aur user ko yeh na lage ke database mein kuch aur ja
   raha hai."

   DATABASE MEIN KUCH NAHI BADLA. Yeh sirf DIKHANE ka tareeqa hai.
   Wajah saaf hai:

       orders         par UNIQUE (site_id, business_date, bill_no)
       cashier_shifts par UNIQUE (site_id, shift_no)

   Bill number `L206-0007` hai, aur `L206-` us offline PC ka node
   code hai. Yeh prefix isi liye laga tha ke do offline PC ek hi
   din "7" na bana dein — warna cloud par sync ke waqt ek bill
   doosre ko mita deta. Is liye database mein number jaisa hai
   waisa hi rehta hai, aur screen par sirf wo hissa chhapta hai jo
   cashier aur gahak ke kaam ka hai.

   `bill_show()`:
       L206-0007  ->  7
       0001       ->  1
       7          ->  7
   Jo number is saanche mein na ho (purana ya haath se likha hua),
   wo JAISA HAI WAISA hi dikhta hai — kuch gum nahi hota.
   ============================================================ */
function bill_show($billNo): string
{
    $b = trim((string)$billNo);
    if ($b === '') return '';
    /* node ka prefix: "L206-0007".
       CHAAR ya us se zyada hindsay LAZMI hain — bill number hamesha
       `str_pad(...,4,'0')` se banta hai. Yeh shart is liye hai ke
       doosri qism ke number is saanche mein na phans jayen:
           ON-123456-42  (customer app ka bill)  -> waisa hi
           APP-1                                  -> waisa hi
       Sirf wohi number kata jata hai jo POS ne khud is shakl mein
       banaya ho. */
    if (preg_match('/^[A-Za-z][A-Za-z0-9]{0,3}-(\d{4,})$/', $b, $m)) {
        return (string)(int)$m[1];
    }
    /* bina prefix, sirf number: "0001" */
    if (preg_match('/^\d+$/', $b)) {
        return (string)(int)$b;
    }
    return $b;
}

/* Account (shift) ka number. `cashier_shifts.shift_seq` har site par
   1 se chalta hai (V181 ki migration purani shifts ko bhi opened_at
   ki tarteeb se number de deti hai). Kisi wajah se seq na mile to
   purana poora naam dikh jata hai — khana khali nahi rehta. */
function acct_show($seq, $shiftNo = ''): string
{
    $n = (int)$seq;
    if ($n > 0) return (string)$n;
    return trim((string)$shiftNo);
}

/* Agla account number is site ke liye. Column na ho (purana node jahan
   migration abhi nahi chali) to NULL — screen par purana poora naam
   dikh jata hai aur account khulna rukta nahi. */
function next_acct_seq(\PDO $p, string $siteId): ?int
{
    try {
        $q = $p->prepare("SELECT COALESCE(MAX(shift_seq),0)+1 FROM cashier_shifts WHERE site_id=?");
        $q->execute([$siteId]);
        return (int)$q->fetchColumn();
    } catch (\Throwable $e) { return null; }
}

// build: V181 build 2026-11-04

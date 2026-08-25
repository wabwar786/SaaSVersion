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

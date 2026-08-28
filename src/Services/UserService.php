<?php
namespace Aio\Services;
use Aio\DB;
use PDO;
final class UserService {
    public static function passwordHash(string $password): array {
        if (defined('PASSWORD_ARGON2ID')) return [password_hash($password,PASSWORD_ARGON2ID),'ARGON2ID'];
        return [password_hash($password,PASSWORD_BCRYPT),'BCRYPT'];
    }
    public static function signup(array $d): string {
        [$hash]=$x=self::passwordHash($d['password']); $id=uuid();
        $q=DB::pdo()->prepare("INSERT INTO signup_requests(id,tenant_id,requested_org_name,full_name,email,phone,password_hash,status,requested_at) VALUES(?,?,?,?,?,?,?,'PENDING',NOW(6))");
        $q->execute([$id,tenant_id(),$d['business']?:null,$d['full_name'],$d['email'],$d['phone']?:null,$hash]); return $id;
    }
    public static function create(array $d,?string $requestId=null): string {
        /* V77 — USERNAME asli pehchan hai, email nahi.
           Pehle email lazmi thi. Chhoti dukanon ke cashiers ke paas email
           hoti hi nahi, is liye malik jhoothi email likhta tha
           (cashier1@gmail.com waghera) — jo na kabhi kaam aayi na sach
           thi. Ab: username lazmi aur unique, email marzi ki. */
        $d['username'] = trim((string)($d['username'] ?? ''));
        $d['email']    = trim((string)($d['email'] ?? ''));

        if ($d['username'] === '' && $d['email'] !== '') {
            $d['username'] = explode('@', $d['email'])[0];
        }
        if ($d['username'] === '') {
            throw new \RuntimeException('Username is required.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $d['username'])) {
            throw new \RuntimeException('Username can use letters, numbers, dot, dash and underscore (3-60 characters).');
        }
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('That email address does not look valid. Leave it blank if there is none.');
        }
        if (strlen((string)($d['password'] ?? '')) < 4 && empty($d['password_hash_override'])) {
            throw new \RuntimeException('Password must be at least 4 characters.');
        }
        $chk = DB::pdo()->prepare("SELECT COUNT(*) FROM users
                                    WHERE tenant_id=? AND LOWER(username)=LOWER(?) AND deleted_at IS NULL");
        $chk->execute([tenant_id(), $d['username']]);
        if ((int)$chk->fetchColumn() > 0) {
            throw new \RuntimeException('That username is already taken. Pick another one.');
        }
        if ($d['email'] !== '') {
            $c2 = DB::pdo()->prepare("SELECT COUNT(*) FROM users
                                       WHERE tenant_id=? AND LOWER(email)=LOWER(?) AND deleted_at IS NULL");
            $c2->execute([tenant_id(), $d['email']]);
            if ((int)$c2->fetchColumn() > 0) {
                throw new \RuntimeException('That email is already used by another user.');
            }
        }

        return DB::tx(function(PDO $pdo) use($d,$requestId){
            $id=uuid(); if(!empty($d['password_hash_override'])){ $hash=$d['password_hash_override']; $algo=$d['password_algo']??'ARGON2ID'; } else { [$hash,$algo]=self::passwordHash($d['password']); }
            $pdo->prepare("INSERT INTO users(id,tenant_id,username,email,phone,full_name,password_hash,password_algo,status,is_tenant_admin,must_change_password) VALUES(?,?,?,?,?,?,?,?,'ACTIVE',?,?)")
                ->execute([$id,tenant_id(),$d['username'],$d['email']?:null,$d['phone']?:null,$d['full_name'],$hash,$algo,!empty($d['is_admin'])?1:0,!empty($d['must_change'])?1:0]);
            $roleId=$d['role_id']??null;
            if($roleId) $pdo->prepare('INSERT INTO user_roles(id,user_id,role_id,site_id,assigned_by) VALUES(?,?,?,?,?)')->execute([uuid(),$id,$roleId,site_id(),current_user()['id']??null]);
            foreach($d['modules']??[] as $moduleId) $pdo->prepare("INSERT INTO user_module_access(id,user_id,site_id,module_id,access_mode) VALUES(?,?,?,?, 'ALLOW')")->execute([uuid(),$id,site_id(),$moduleId]);
            foreach($d['form_permissions']??[] as $p){
                $pdo->prepare("INSERT INTO user_form_permissions(id,user_id,site_id,module_key,form_key,can_view,can_add,can_edit,can_delete,can_approve,can_export,can_print) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([uuid(),$id,site_id(),$p['module_key'],$p['form_key'],$p['view'],$p['add'],$p['edit'],$p['delete'],$p['approve'],$p['export'],$p['print']]);
            }
            if($requestId) $pdo->prepare("UPDATE signup_requests SET status='APPROVED',reviewed_by_user_id=?,reviewed_at=NOW(6) WHERE id=?")->execute([current_user()['id']??null,$requestId]);
            return $id;
        });
    }
}

// build: V17.1 build 2026-08-25

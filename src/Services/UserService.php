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
        return DB::tx(function(PDO $pdo) use($d,$requestId){
            $id=uuid(); if(!empty($d['password_hash_override'])){ $hash=$d['password_hash_override']; $algo=$d['password_algo']??'ARGON2ID'; } else { [$hash,$algo]=self::passwordHash($d['password']); }
            $pdo->prepare("INSERT INTO users(id,tenant_id,username,email,phone,full_name,password_hash,password_algo,status,is_tenant_admin,must_change_password) VALUES(?,?,?,?,?,?,?,?,'ACTIVE',?,?)")
                ->execute([$id,tenant_id(),$d['username']?:null,$d['email'],$d['phone']?:null,$d['full_name'],$hash,$algo,!empty($d['is_admin'])?1:0,!empty($d['must_change'])?1:0]);
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

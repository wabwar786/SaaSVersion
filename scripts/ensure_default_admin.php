<?php
declare(strict_types=1);require_once dirname(__DIR__).'/src/bootstrap.php';
use Aio\DB;use Aio\Services\UserService;
$p=DB::pdo();$q=$p->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=? AND is_tenant_admin=1 AND status='ACTIVE'");$q->execute([tenant_id()]);if((int)$q->fetchColumn()>0){echo "ADMIN_READY\n";exit;}
$role=$p->prepare("SELECT id FROM roles WHERE tenant_id=? AND name='Owner / Admin' LIMIT 1");$role->execute([tenant_id()]);$roleId=$role->fetchColumn();
$mods=array_column($p->query("SELECT id FROM platform_modules WHERE is_active=1 ORDER BY sort_order")->fetchAll(),'id');
UserService::create(['full_name'=>'System Administrator','email'=>'admin@urbanspoon.local','username'=>'admin','phone'=>'','password'=>'Admin@123','role_id'=>$roleId,'modules'=>$mods,'is_admin'=>1,'must_change'=>0]);
echo "DEFAULT_ADMIN_CREATED\n";

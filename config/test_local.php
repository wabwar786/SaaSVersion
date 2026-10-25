<?php
$c = require __DIR__.'/local.php';
$c['app']['role']='local'; $c['app']['debug']=true;
$c['app']['base_url']='http://127.0.0.1:8099';
$c['app']['tenant_id'] = '444b044b-8dec-4348-833d-873687052e94';
$c['app']['site_id']   = 'bfd4c20c-431e-4f65-9c7d-45e6bf736b49';
$c['db']['database']='aio_cloud'; $c['db']['username']='aio'; $c['db']['password']='aio123';
$c['sync']['enabled']=false; $c['sync']['cloud_api_url']='';
return $c;

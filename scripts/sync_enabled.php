<?php
require dirname(__DIR__).'/src/bootstrap.php';
$c=$GLOBALS['config']['sync']??[];
echo (!empty($c['enabled']) && !empty($c['cloud_api_url'])) ? '1' : '0';

// build: V17.1 build 2026-08-25

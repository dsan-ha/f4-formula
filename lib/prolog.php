<?php
define('SITE_ROOT',dirname(__DIR__).'/');
require_once SITE_ROOT . 'lib/vendor/autoload.php';

if ((float)PCRE_VERSION<7.9)
    trigger_error('PCRE version is out of date');

function f4(){
    return \App\F4::instance();
}
$f4 = f4();
// Load configuration
$f4->config('lib/config.yaml');
try {  
    App\Base\DataLoader::loadOrdered();
} catch (Exception $e) {
    $f4->error(500,$e->getMessage());
}
<?php

define('SITE_ROOT', dirname(__DIR__) . '/');
require_once SITE_ROOT . 'lib/vendor/autoload.php';

if ((float)PCRE_VERSION < 7.9) {
    trigger_error('PCRE version is out of date');
}

/**
 * Boundary helper for procedural/bootstrap code only.
 * DI-managed classes must receive F4 through constructor injection.
 */
function f4(): \App\F4
{
    return \App\Base\Kernel::instance()->f4();
}

$f4 = f4();

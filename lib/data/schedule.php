<?php if(!defined('SITE_ROOT')) exit();
$f4 = \App\F4::instance();

$f4->schedule(function () {
    // Очистка бана
    //\App\Utils\Firewall::cronCleanup();
}, '0 * * * *'); // каждый час

$f4->schedule(function () {
    // Ротация логов
    //\App\Utils\Log\LogRotator::rotateDirectory();
}, '0 * * * *'); // каждый час
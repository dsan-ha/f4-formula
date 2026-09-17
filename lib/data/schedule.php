<?php if(!defined('SITE_ROOT')) exit();

use App\Base\Kernel;
use App\Utils\Scheduler;

/** @var Scheduler $scheduler */
$scheduler = Kernel::instance()->get(Scheduler::class);

$scheduler->add(function () {
    // Очистка бана
    // Kernel/composition root должен заранее получить нужный сервис и замкнуть его сюда.
}, '0 * * * *');

$scheduler->add(function () {
    // Ротация логов
}, '0 * * * *');

<?php

require_once __DIR__ . '/prolog.php';

use App\Base\Kernel;
use App\Utils\Scheduler;

$kernel = Kernel::instance();
$f4 = $kernel->f4();

date_default_timezone_set((string)$f4->get('TZ'));

$kernel->loadSchedules();

/** @var Scheduler $scheduler */
$scheduler = $kernel->get(Scheduler::class);
$scheduler->run();

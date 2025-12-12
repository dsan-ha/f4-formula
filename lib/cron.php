<?php
require_once 'prolog.php';
require_once(SITE_ROOT . 'lib/data/schedule.php');
require_once(SITE_ROOT . 'local/data/schedule.php');
$f4 = \App\F4::instance();

date_default_timezone_set($f4->get('TZ'));

$f4->runScheduledTasks();
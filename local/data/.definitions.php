<?php if(!defined('SITE_ROOT')) exit();
use App\Service;
use App\Service\DB\SQL;
use App\Service\DataManagerRegistry;
use function DI\autowire;
use function DI\create;
use function DI\get;

$f4 = f4();
$dsn  = $f4->get('db.dsn');
$user = $f4->get('db.login');
$pass = $f4->get('db.pass');

return [
    SQL::class => create(SQL::class)->constructor($dsn, $user, $pass, [], get(\App\F4::class)),
    DataManagerRegistry::class => autowire(DataManagerRegistry::class)
];







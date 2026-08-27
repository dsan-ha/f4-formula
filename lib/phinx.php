<?php
// vendor/bin/phinx create InitTelegramBotSchema

use App\F4;
$f4 = F4::instance();
$dsn  = $f4->g('db.dsn','mysql:host=mysql-8.4;port=3306;dbname=fff_skeleton');
$ar_dsn = explode(';',$dsn);
$ar_db = [];

foreach ($ar_dsn as $value) {
    $d = explode('=',$value);
    $ar_db[$d[0]] = $d[1];
}
$user = $f4->g('db.login','root');
$pass = $f4->g('db.pass','root');

$migration_config = [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $ar_db['mysql:host'] ?? 'mysql-8.4',
            'name' => $ar_db['dbname'] ?? 'fff_skeleton',
            'user' => $user,
            'pass' => $pass,
            'port' => $ar_db['port'] ?? 3306,
            'charset' => 'utf8',
        ],
    ],
];

if(defined('SITE_ROOT')){
    $f4 = F4::instance();
    $modules = (array)$f4->get('MODULES');

    $migrationPaths = [
        'App\\Migrations' => __DIR__ . '/database/migrations',
    ];

    $seedPaths = [
        'App\\Seeds' => __DIR__ . '/database/seeds',
    ];

    foreach ($modules as $slug => $module) {
        if (empty($module['active'])) {
            continue;
        }

        $basePath = rtrim((string)($module['base_path'] ?? ''), '/\\');
        $namespace = trim((string)($module['namespace'] ?? $slug), '\\');

        if ($basePath && is_dir($basePath . '/db/migrations')) {
            $migrationPaths[$namespace . '\\Migrations'] = $basePath . '/db/migrations';
        }

        if ($basePath && is_dir($basePath . '/db/seeds')) {
            $seedPaths[$namespace . '\\Seeds'] = $basePath . '/db/seeds';
        }
    }
    $migration_config['paths']['migrations'] = $migrationPaths;
    $migration_config['paths']['seeds'] = $seedPaths;
    $migration_config['migration_base_class'] = \App\Migrations\ModuleMigration::class;
}

return $migration_config;
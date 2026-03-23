<?php
// vendor/bin/phinx create InitTelegramBotSchema

use App\F4;

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
            'host' => 'mysql-5.7',
            'name' => 'fff_skeleton',
            'user' => 'root',
            'pass' => 'root',
            'port' => 3306,
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

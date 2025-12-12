<?php
// vendor/bin/phinx create InitTelegramBotSchema
return [
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

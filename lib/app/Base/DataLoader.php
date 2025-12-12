<?php
namespace App\Base;

use App\F4;
use Symfony\Component\Yaml\Yaml;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;


class DataLoader
{
    private static array  $order = [
        'constants.php',
        'dependencies.php',
        'helpers.php',
        'middleware.php',
        'routes.php'
    ];

    private static function full(string $relPath): string
    {
        return SITE_ROOT.$relPath;
    }

    public static function loadOrdered(array $exclude = []): void
    {
        if(!defined('SITE_ROOT')) throw new \Exception("Main path Defines don't define");
        $folders = [
            'lib/data', 
            'local/data'
        ];
        foreach ($folders as $relPath) {
            foreach (self::$order as $file) {
                $path = self::full("$relPath/$file");
                if (is_file($path) && !in_array($file, $exclude)) require_once $path;
            }
        }
    }
}

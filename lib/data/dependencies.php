<?php if(!defined('SITE_ROOT')) exit();
use App\Service;
use App\Utils\Cache\FileCacheAdapter;
use App\Base\ServiceLocator;
use Symfony\Component\Yaml\Yaml;
use DI\ContainerBuilder;

$sl = new ServiceLocator();
$f4 = App\F4::instance();

// Загружаем все определения
$definitions = [];

$paths = [
    'lib/data',   // ядро
    'local/data', // локальные переопределения
];

$files = ['services.yaml', '.definitions.php']; //файлы с зависимостями

foreach ($paths as $dir) {
    foreach ($files as $fileName) {
        $fullPath = SITE_ROOT . "{$dir}/{$fileName}";
        if (!file_exists($fullPath)) {
            continue;
        }

        $ext = pathinfo($fullPath, PATHINFO_EXTENSION);

        if ($ext === 'yaml' || $ext === 'yml') {
            $yaml = Yaml::parseFile($fullPath);
            if (!empty($yaml['services'])) {
                $sl->addDefinitions($yaml['services']);
            }
        } elseif ($ext === 'php') {
            $definitions = require $fullPath;
            if (is_array($definitions)) {
                $sl->addDefinitions($definitions);
            }
        }
    }
}
$containerBuilder = new ContainerBuilder();
$sl = $sl->useAutowiring($f4->get('DI_AUTOWIRING'))
    ->initContainer($containerBuilder);
$f4->set('CONTAINER',$sl);

$f4->init();

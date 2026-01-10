<?php
namespace App\Modules;

use App\F4;
use App\Utils\Cache;
use App\Utils\FileWalker;
use App\Modules\ModuleAutoloader;
use Symfony\Component\Yaml\Yaml;

final class ModuleRegistry
{
    private F4 $f4;
    private Cache $cache;

    /** @var array<string,array> */
    private array $modules = []; // slug => descriptor

    public function __construct(F4 $f4, Cache $cache)
    {
        $this->f4 = $f4;
        $this->cache = $cache;
    }

    public function boot(): void
    {
        $this->discover_modules();
        $this->discover_autoload();
        $this->load_modules();
        // чтобы из любого места можно было посмотреть что поднялось
        $this->f4->set('MODULES', $this->modules);
    }

    public function all(): array { return $this->modules; }

    private function discover_autoload(): void
    {
        /** @var ModuleAutoloader $loader */
        $loader = $this->f4->getDI(ModuleAutoloader::class);

        // регистрируем ОДИН раз
        static $registered = false;
        if (!$registered) {
            $loader->register(1);
            $registered = true;
        }

        foreach ($this->modules as $slug => $m) {
            $ns = (string)($m['namespace'] ?? '');
            if ($ns === '') {
                // theoretically не должно случаться, потому что namespace обязателен
                throw new \RuntimeException("Module '{$slug}' missing namespace in registry.");
            }

            $libDir = rtrim($m['base_path'], '/\\') . '/lib';
            if (!is_dir($libDir)) continue; // lib папка не обязательна

            $loader->addPsr4($ns.'\\', $libDir);
        }
    }

    private function discover_modules(): void
    {

        // кэшируем результат скана по сигнатуре (mtime файлов setting.yaml)
        [$signature, $settingsFiles] = $this->calcSignature();

        $cacheKey = 'modules.registry.v1';
        $cached = $this->f4->cache_get($cacheKey, null);
        if (is_array($cached) && ($cached['signature'] ?? '') === $signature) {
            $this->modules = $cached['modules'] ?? [];
            return;
        }

        $found = [];

        // Важно: local должен перекрывать lib
        $roots = [
            SITE_ROOT.'lib/app/Modules',
            SITE_ROOT.'local/app/Modules',
        ];

        foreach ($roots as $root) {
            if (!is_dir($root)) continue;

            // берём только setting.yaml на глубине "одна папка = модуль"
            // проще всего так: собрали все */setting.yaml и по ним итерируемся
            $files = FileWalker::collect($root, ['*/setting.yaml'], [], []);
            foreach ($files as $full => $rel) {
                $moduleDir = dirname($full);
                $yaml = Yaml::parseFile($full) ?: [];
                $settings = ModuleSettings::fromArray(is_array($yaml) ? $yaml : [], $full);
                $this->scaffoldModuleData($moduleDir);

                $slug = $this->makeSlugFromDir($moduleDir);

                $includePath = $moduleDir . '/' . $settings->include;

                $found[$slug] = [
                    'slug' => $slug,
                    'name' => $settings->name,
                    'active' => $settings->active,
                    'namespace' => $settings->namespace,
                    'priority' => $settings->priority,
                    'base_path' => $moduleDir,
                    'settings_path' => $full,
                    'include_path' => $includePath,
                    'settings' => $settings->raw,
                    'source_root' => $root,
                ];
            }
        }

        $this->modules = $found;

        $this->f4->cache_set($cacheKey, [
            'signature' => $signature,
            'modules' => $this->modules,
        ], 0);
    }

    private function load_modules(): void
    {
        // сортировка по priority (больше = раньше)
        uasort($this->modules, fn($a,$b) => ($b['priority'] ?? 50) <=> ($a['priority'] ?? 50));

        foreach ($this->modules as $slug => $m) {
            if (empty($m['active'])) continue;

            $inc = $m['include_path'];
            if (!is_file($inc)) {
                throw new \RuntimeException("Module '{$slug}' include.php not found: {$inc}");
            }
            require_once $inc;
        }
    }

    private function scaffoldModuleData(string $moduleDir): void
    {
        $dataDir = rtrim($moduleDir, '/\\') . '/data';

        if (!is_dir($dataDir)) {
            // если модуль лежит в lib и права только на чтение — mkdir может не получиться
            @mkdir($dataDir, 0775, true);
        }

        $files = [
            'routes.php' => $this->tplRoutes(),
            'dependencies.php' => $this->tplDependencies(),
            'schedule.php' => $this->tplSchedule(),
            'constants.php' => $this->tplConstants(),
        ];

        foreach ($files as $name => $content) {
            $path = $dataDir . '/' . $name;

            if (is_file($path)) continue;          // не перетираем
            if (!is_dir($dataDir)) continue;       // если папка не создалась, тихо пропускаем или кидаем ошибку в dev

            $this->atomicWriteIfNotExists($path, $content);
        }
    }

    /** @return array{0:string,1:array} */
    private function calcSignature(): array
    {
        $roots = [
            SITE_ROOT.'lib/app/Modules',
            SITE_ROOT.'local/app/Modules',
        ];

        $filesAll = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) continue;
            $files = FileWalker::collect($root, ['*/setting.yaml'], [], []);
            foreach ($files as $full => $rel) $filesAll[] = $full;
        }
        sort($filesAll);

        $parts = [];
        foreach ($filesAll as $f) {
            $parts[] = $f . ':' . (is_file($f) ? filemtime($f) : 0);
        }

        return [sha1(implode('|', $parts)), $filesAll];
    }

    private function makeSlugFromDir(string $moduleDir): string
    {
        $name = basename(rtrim($moduleDir, '/\\'));

        // 1) только латиница/цифры/_.-
        if (!preg_match('/^[A-Za-z0-9_\.\-]+$/', $name)) {
            throw new \RuntimeException("Module folder '{$name}' has invalid chars. Allowed: [A-Za-z0-9_.-]");
        }

        // 2) lowercase
        $slug = strtolower($name);

        // 3) . и - в _
        $slug = str_replace(['.', '-'], '_', $slug);

        // 4) схлопываем подряд идущие _
        $slug = preg_replace('/_+/', '_', $slug);

        // 5) trim _
        $slug = trim($slug, '_');

        if ($slug === '') {
            throw new \RuntimeException("Module folder '{$name}' produces empty slug.");
        }

        return $slug;
    }

    private function atomicWriteIfNotExists(string $path, string $content): void
    {
        // двойная проверка на гонки
        if (is_file($path)) return;

        $tmp = $path . '.tmp.' . uniqid('', true);
        file_put_contents($tmp, $content, LOCK_EX);
        @chmod($tmp, 0664);

        // rename атомарен на одном FS
        @rename($tmp, $path);

        // если rename не случился (например, другой процесс успел) — чистим tmp
        if (!is_file($path) && is_file($tmp)) @unlink($tmp);
    }

    private function tplRoutes(): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn function(\\App\\F4 \$f4): void {\n    // \$f4->route('GET /test', 'Test\\\\Controller\\\\Home->index');\n};\n";
    }
    private function tplDependencies(): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn function(\$di): void {\n    // \$di->set(Test\\\\Service\\\\Foo::class, DI\\\\create(...));\n};\n";
    }
    private function tplSchedule(): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn [\n    // [\n    //   'id' => 'test:demo',\n    //   'cron' => '*/5 * * * *',\n    //   'handler' => 'Test\\\\Jobs\\\\DemoJob->run',\n    // ],\n];\n";
    }
    private function tplConstants(): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn [\n    // 'TEST_CONST' => 123,\n];\n";
    }

}

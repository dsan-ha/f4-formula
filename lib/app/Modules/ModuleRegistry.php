<?php
declare(strict_types=1);

namespace App\Modules;

use App\F4;
use App\Base\ServiceLocator;
use App\Modules\Install\ModuleInstaller;
use App\Utils\Fs;
use Symfony\Component\Yaml\Yaml;
use App\Utils\FileCacheNonContainer;

final class ModuleRegistry
{
    private F4 $f4;
    private ModuleAutoloader $autoloader;
    private FileCacheNonContainer $cache;

    /** @var array<string,array<string,mixed>> */
    private array $modules = [];

    public function __construct(F4 $f4, ?ModuleAutoloader $autoloader = null, ?string $cachePath = null)
    {
        $this->f4 = $f4;
        $this->autoloader = $autoloader ?? new ModuleAutoloader();

        $cacheDir = $cachePath ?: SITE_ROOT . 'lib/tmp/cache/modules';
        $this->cache = new FileCacheNonContainer($cacheDir);
    }

    public function bootstrap(): void
    {
        $this->discoverModules();
        $this->f4->set('MODULES', $this->modules);
        $this->registerAutoload();
        $this->installMissing();
    }

    public function all(): array
    {
        return $this->modules;
    }

    public function getAutoloader(): ModuleAutoloader
    {
        return $this->autoloader;
    }

    public function addDefinitionsTo(ServiceLocator $sl): void
    {
        foreach ($this->getDataFiles('.definitions.php') as $path) {
            $payload = require $path;

            if (is_array($payload)) {
                $sl->addDefinitions($payload);
                continue;
            }

            if (is_callable($payload)) {
                $result = $payload($sl);
                if (is_array($result)) {
                    $sl->addDefinitions($result);
                }
            }
        }
    }

    public function loadBootstrapFiles(array $files): void
    {
        foreach ($files as $fileName) {
            foreach ($this->getDataFiles($fileName) as $path) {
                $this->applyBootstrapFile($fileName, $path);
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function getDataFiles(string $fileName): array
    {
        $items = [];
        $modules = $this->modules;

        uasort($modules, fn(array $a, array $b) => ($b['priority'] ?? 50) <=> ($a['priority'] ?? 50));

        foreach ($modules as $m) {
            if (empty($m['active'])) {
                continue;
            }

            $path = rtrim((string)$m['base_path'], '/\\') . '/data/' . $fileName;
            if (is_file($path)) {
                $items[] = $path;
            }
        }

        return $items;
    }

    private function applyBootstrapFile(string $fileName, string $path): void
    {
        $payload = require $path;

        /*switch ($fileName) {
            case 'routes.php':
                if (is_callable($payload)) {
                    $payload($this->f4);
                }
                break;

            case 'schedule.php':
                if (is_array($payload)) {
                    $current = $this->f4->get('SCHEDULE', []);
                    $this->f4->set('SCHEDULE', array_merge($current, $payload));
                }
                break;

            case 'constants.php':
                if (is_array($payload)) {
                    foreach ($payload as $name => $value) {
                        if (is_string($name) && $name !== '' && !defined($name)) {
                            define($name, $value);
                        }
                    }
                }
                break;
        }*/
    }

    private function registerAutoload(): void
    {
        static $registered = false;

        if (!$registered) {
            $this->autoloader->register(1);
            $registered = true;
        }

        foreach ($this->modules as $slug => $m) {
            $ns = (string)($m['namespace'] ?? '');
            if ($ns === '') {
                throw new \RuntimeException("Module '{$slug}' missing namespace.");
            }

            $libDir = rtrim((string)$m['base_path'], '/\\') . '/lib';
            if (is_dir($libDir)) {
                $this->autoloader->addPsr4($ns . '\\', $libDir);
            }
        }
    }

    private function installMissing(): void
    {
        $installer = new ModuleInstaller($this->f4);
        $installer->installMissing($this->modules);
    }

    private function discoverModules(): void
    {
        [$signature] = $this->calcSignature();

        $cacheKey = 'modules.registry.v1';
        $cached = $this->cache->get($cacheKey);

        if (
            is_array($cached)
            && ($cached['signature'] ?? '') === $signature
            && !empty($cached['modules'])
            && is_array($cached['modules'])
        ) {
            $this->modules = $cached['modules'];
            return;
        }

        $found = [];
        $roots = [
            SITE_ROOT . 'lib/modules',
            SITE_ROOT . 'local/modules',
        ];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $files = \App\Utils\Fs::collect($root, ['*/setting.yaml'], [], []);
            foreach ($files as $full => $rel) {
                $moduleDir = dirname($full);
                $yaml = \Symfony\Component\Yaml\Yaml::parseFile($full) ?: [];
                $settings = ModuleSettings::fromArray(is_array($yaml) ? $yaml : [], $full);

                $this->scaffoldModuleData($moduleDir);

                $slug = $this->makeSlugFromDir($moduleDir);

                $found[$slug] = [
                    'slug' => $slug,
                    'name' => $settings->name,
                    'active' => $settings->active,
                    'namespace' => $settings->namespace,
                    'priority' => $settings->priority,
                    'base_path' => $moduleDir,
                    'settings_path' => $full,
                    'include_path' => $moduleDir . '/' . $settings->include,
                    'settings' => $settings->raw,
                    'source_root' => $root,
                ];
            }
        }

        $this->modules = $found;

        $this->cache->set($cacheKey, [
            'signature' => $signature,
            'modules' => $this->modules,
        ]);
    }

    private function calcSignature(): array
    {
        $roots = [
            SITE_ROOT . 'lib/modules',
            SITE_ROOT . 'local/modules',
        ];

        $filesAll = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $files = Fs::collect($root, ['*/setting.yaml'], [], []);
            foreach ($files as $full => $rel) {
                $filesAll[] = $full;
            }
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
        $slug = strtolower(str_replace(['.', '-'], '_', $name));
        $slug = preg_replace('/_+/', '_', $slug);
        $slug = trim((string)$slug, '_');

        if ($slug === '') {
            throw new \RuntimeException("Module folder '{$name}' produces empty slug.");
        }

        return $slug;
    }

    private function scaffoldModuleData(string $moduleDir): void
    {
        $dataDir = rtrim($moduleDir, '/\\') . '/data';

        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }

        $files = [
            'routes.php' => $this->tplRoutes(),
            '.definitions.php' => $this->tplDefinitions(),
            'schedule.php' => $this->tplSchedule(),
            'constants.php' => $this->tplConstants(),
        ];

        foreach ($files as $name => $content) {
            $path = $dataDir . '/' . $name;
            if (!is_dir($dataDir)) {
                continue;
            }
            if (!is_file($path)) {
                file_put_contents($path, $content, LOCK_EX);
            }
        }
    }

    private function tplRoutes(): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn function(\\App\\F4 \$f4): void {\n    // \$f4->route('GET /test', 'Test\\\\Controller\\\\Home->index');\n};\n";
    }

    private function tplDefinitions(): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nuse function DI\\create;\n\nreturn [\n    // Test\\\\Service\\\\Foo::class => create(Test\\\\Service\\\\Foo::class),\n];\n";
    }

    private function tplSchedule(): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn [\n    // ['id' => 'test:demo', 'cron' => '*/5 * * * *', 'handler' => 'Test\\\\Jobs\\\\DemoJob->run'],\n];\n";
    }

    private function tplConstants(): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn [\n    // 'TEST_CONST' => 123,\n];\n";
    }
}
<?php

declare(strict_types=1);

namespace App\Base;

use App\F4;
use App\Http\ErrorHandler;
use App\Modules\Install\ModuleInstaller;
use App\Modules\ModuleAutoloader;
use App\Modules\ModuleRegistry;
use DI\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;
use function DI\value;

/**
 * Единственная точка bootstrap/composition root F4.
 *
 * Kernel владеет ServiceLocator и порядком инициализации. Прикладные классы
 * не должны обращаться к Kernel напрямую: они получают зависимости через DI.
 */
final class Kernel
{
    private static ?self $instance = null;

    private ServiceLocator $services;
    private F4Store $store;
    private F4 $f4;
    private ?ModuleAutoloader $moduleAutoloader = null;
    private ?ModuleRegistry $moduleRegistry = null;

    private bool $booting = false;
    private bool $booted = false;
    private bool $schedulesLoaded = false;

    private function __construct()
    {
        $this->services = new ServiceLocator();
        $this->store = new F4Store();
        $this->f4 = new F4($this->services, $this->store);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            // instance фиксируется до boot(), чтобы bootstrap-файлы могли вызвать f4()
            // без рекурсивного создания второго Kernel.
            self::$instance = new self();
            self::$instance->boot();
        }

        return self::$instance;
    }

    public function boot(): self
    {
        if ($this->booted || $this->booting) {
            return $this;
        }

        $this->booting = true;

        try {
            $this->f4->bootstrap();
            $this->f4->config(SITE_ROOT . 'lib/config.yaml');
            ErrorHandler::instance()->setDebug((int)$this->f4->get('DEBUG') > 0);

            // Core constants доступны до загрузки definitions.
            $this->requireDataFile('lib/data/constants.php');

            // На этой фазе только discovery/autoload. Installer'ы ещё НЕ запускаются:
            // им уже может понадобиться полностью собранный PHP-DI.
            $this->moduleAutoloader = new ModuleAutoloader();
            $this->moduleRegistry = new ModuleRegistry($this->f4, $this->moduleAutoloader);
            $this->moduleRegistry->bootstrap();

            $this->loadDefinitions();
            $this->moduleRegistry->addDefinitionsTo($this->services);

            $this->services->addDefinitions([
                F4::class => value($this->f4),
                F4Store::class => value($this->store),
                ErrorHandler::class => value(ErrorHandler::instance()),
                ServiceLocator::class => value($this->services),
                ModuleRegistry::class => value($this->moduleRegistry),
                ModuleAutoloader::class => value($this->moduleAutoloader),
            ]);

            $this->services
                ->useAutowiring((bool)$this->f4->get('DI_AUTOWIRING'))
                ->initContainer(new ContainerBuilder());

            // Теперь DI полностью готов: можно запускать installers/migrations.
            $this->moduleRegistry->installMissing(
                $this->services->get(ModuleInstaller::class)
            );

            // schedule.php намеренно НЕ загружается в HTTP bootstrap.
            $this->moduleRegistry->loadBootstrapFiles([
                'constants.php',
                'routes.php',
            ]);

            $this->loadRuntimeData();
            $this->booted = true;
        } finally {
            $this->booting = false;
        }

        return $this;
    }

    /**
     * Composition-root accessor. В обычных классах вместо него используется DI.
     */
    public function f4(): F4
    {
        return $this->f4;
    }

    /**
     * Composition-root accessor. Не использовать как Service Locator в прикладных классах.
     */
    public function get(string $id): mixed
    {
        return $this->services->get($id);
    }

    /**
     * Допустимо для динамической инфраструктуры/optional integrations.
     */
    public function has(string $id): bool
    {
        return $this->services->has($id);
    }

    public function services(): ServiceLocator
    {
        return $this->services;
    }

    /**
     * Schedule-фаза запускается только cron/CLI entry point после полного boot().
     */
    public function loadSchedules(): self
    {
        if ($this->schedulesLoaded) {
            return $this;
        }

        if (!$this->booted) {
            $this->boot();
        }

        $this->requireDataFile('lib/data/schedule.php');
        $this->requireDataFile('local/data/schedule.php');

        if ($this->moduleRegistry) {
            $this->moduleRegistry->loadBootstrapFiles(['schedule.php']);
        }

        $this->schedulesLoaded = true;
        return $this;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    private function loadDefinitions(): void
    {
        foreach (['lib/data', 'local/data'] as $dir) {
            $yamlPath = SITE_ROOT . $dir . '/services.yaml';
            if (is_file($yamlPath)) {
                $yaml = Yaml::parseFile($yamlPath);
                if (!empty($yaml['services']) && is_array($yaml['services'])) {
                    $this->services->addDefinitions($yaml['services']);
                }
            }

            $phpPath = SITE_ROOT . $dir . '/.definitions.php';
            if (is_file($phpPath)) {
                $definitions = require $phpPath;
                if (is_array($definitions)) {
                    $this->services->addDefinitions($definitions);
                }
            }
        }
    }

    private function loadRuntimeData(): void
    {
        // dependencies.php больше не участвует в штатном bootstrap.
        foreach (['helpers.php', 'middleware.php', 'routes.php'] as $file) {
            $this->requireDataFile('lib/data/' . $file);
        }

        foreach (['constants.php', 'helpers.php', 'middleware.php', 'routes.php'] as $file) {
            $this->requireDataFile('local/data/' . $file);
        }
    }

    private function requireDataFile(string $relativePath): void
    {
        $path = SITE_ROOT . ltrim($relativePath, '/\\');
        if (is_file($path)) {
            require_once $path;
        }
    }
}

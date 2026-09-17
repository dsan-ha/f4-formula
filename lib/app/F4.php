<?php

namespace App;

use Symfony\Component\Yaml\Yaml;
use App\Base\CookieService;
use App\Base\F3Tools;
use App\Base\F3Helpers;
use App\Base\F4Store;
use App\Base\ServiceLocator;
use App\Http\Request;
use App\Http\Response;
use App\Http\MiddlewareState;
use App\Http\Router;
use App\Utils\Cache;

class F4
{
    use F3Tools, F3Helpers;

    /**
     * @var Base|null
     */
    protected static $fw = null;

    private ServiceLocator $services;
    private F4Store $store;
    private ?\App\Http\Environment $environment = null;

    public function __construct(ServiceLocator $services, F4Store $store)
    {
        $this->services = $services;
        $this->store = $store;
    }

    protected function storeInternal(): F4Store
    {
        return $this->store;
    }

    protected function setEnvironmentInternal(\App\Http\Environment $environment): void
    {
        $this->environment = $environment;
    }

    protected function environmentInternal(): \App\Http\Environment
    {
        return $this->environment ?? \App\Http\Environment::instance();
    }

    protected function requestInternal(): \App\Http\Request
    {
        return $this->environmentInternal()->getRequest();
    }

    /**
     * @deprecated Stage 2 removed the F4 singleton facade.
     */
    public static function instance(): self
    {
        throw new \LogicException('Deprecated F4 API: F4::instance() is no longer allowed. Inject App\\F4 through the constructor or use f4() only in composition roots.');
    }

    /**
     * @deprecated F4 is no longer a public service locator.
     */
    public function getDI(string $id): mixed
    {
        throw new \LogicException("Deprecated F4 API: F4::getDI() is no longer allowed. Inject {$id} through the constructor.");
    }

    /**
     * @deprecated F4 is no longer a public service locator.
     */
    public function hasDI(string $id): bool
    {
        throw new \LogicException("Deprecated F4 API: F4::hasDI() is no longer allowed. Requested id: {$id}.");
    }

    /** Internal infrastructure accessors. Never expose these as application API. */
    private function routerService(): Router
    {
        return $this->services->get(Router::class);
    }

    protected function cacheServiceInternal(): Cache
    {
        return $this->services->get(Cache::class);
    }

    protected function hasCacheServiceInternal(): bool
    {
        return $this->services->has(Cache::class);
    }

    protected function cookieServiceInternal(): CookieService
    {
        return $this->services->get(CookieService::class);
    }

    protected function requestServiceInternal(): Request
    {
        return $this->services->get(Request::class);
    }

    protected function resolveHandlerClassInternal(string $class): object
    {
        try {
            $instance = $this->services->get($class);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Unable to resolve handler '{$class}' from PHP-DI.", 0, $e);
        }

        if (!is_object($instance)) {
            throw new \RuntimeException("Resolved handler '{$class}' is not an object.");
        }

        return $instance;
    }

    public function run()
    {
        $router = $this->routerService();
        return $router->run();
    }

    public function route($pattern, $handler, $ttl = 0, $kbps = 0)
    {
        $router = $this->routerService();
        if (is_array($pattern)) {
            foreach ($pattern as $item) {
                $this->route($item, $handler, $ttl, $kbps);
            }
            return;
        }

        return $router->route($pattern, $handler, $ttl, $kbps);
    }

    public function group($chainUrlGroup = '')
    {
        return $this->routerService()->group($chainUrlGroup);
    }

    public function reroute($url = null, $permanent = false, $die = true)
    {
        $this->routerService()->reroute($url, $permanent, $die);
    }

    public function redirect($pattern, $url, $permanent = true)
    {
        $this->routerService()->redirect($pattern, $url, $permanent);
    }

    /**
     * Load config from YAML.
     */
    public function config(string $file): void
    {
        if (!file_exists($file)) {
            throw new \RuntimeException("Config file not found: $file");
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);

        switch (strtolower($ext)) {
            case 'yaml':
            case 'yml':
                try {
                    $config = Yaml::parseFile($file);
                    if (!is_array($config)) {
                        throw new \UnexpectedValueException("Invalid YAML config format: $file");
                    }

                    foreach ($config as $key => $val) {
                        if (is_array($val)) {
                            $this->mset($val, $key . '.');
                        } else {
                            $this->set($key, $val);
                        }
                    }
                } catch (\Exception $e) {
                    throw new \RuntimeException('YAML parse error: ' . $e->getMessage(), 0, $e);
                }
                break;

            default:
                throw new \RuntimeException("Unsupported config file extension: $ext");
        }
    }

    /** Add middleware to the router. */
    public function add($handler, ?MiddlewareState $state = null)
    {
        $router = $this->routerService();

        if ($state) {
            $router->addMiddleware($handler, $state);
        } else {
            $router->addMiddleware($handler);
        }

        return $this;
    }

    /**
     * @deprecated Scheduling moved to Scheduler and Kernel::loadSchedules().
     */
    public function schedule(callable $callback, string $expression = '* * * * *'): void
    {
        throw new \LogicException('Deprecated F4 API: F4::schedule() is no longer allowed. Use Scheduler from the composition root.');
    }

    /**
     * @deprecated Scheduling moved to Scheduler and Kernel::loadSchedules().
     */
    public function runScheduledTasks(): void
    {
        throw new \LogicException('Deprecated F4 API: F4::runScheduledTasks() is no longer allowed. Use Kernel::loadSchedules() and Scheduler.');
    }

    /**
     * Retrieve contents of hive key with optional default.
     */
    public function g($key, $def = null)
    {
        $val = $this->ref($key, false);
        if (is_null($val)) {
            if (!is_null($def)) {
                $this->set($key, $def);
                return $def;
            }

            if ($this->hasCacheServiceInternal() && $this->cache_exists($this->hash($key) . '.var', $data)) {
                return $data;
            }
        }

        return $val;
    }

    public function json(Response $res): Response
    {
        $bodyArray = $res->makeBody();
        return $res
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(json_encode($bodyArray, JSON_UNESCAPED_UNICODE));
    }
}

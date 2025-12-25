<?php

namespace App;

use App\Utils\Scheduler;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use App\Base\F3Tools;
use App\Http\Response;


class F4
{
    use F3Tools;
    /**
     * @var Base|null
     */
    protected static $fw = null;


    /**
     * 
     */
    public function run()
    {
        $router = $this->get('Router') ?? throw new \RuntimeException(self::E_Router);
        if ($router->blacklisted($this->ip()))
            // Spammer detected
            $this->error(403);
        $router->run();
    }

    public function route($pattern,$handler,$ttl=0,$kbps=0){
        $router = $this->get('Router') ?? throw new \RuntimeException(self::E_Router);
        if (is_array($pattern)) {
            foreach ($pattern as $item)
                $this->route($item,$handler,$ttl,$kbps);
            return;
        } else {
            return $router->route($pattern,$handler,$ttl,$kbps);
        }
    }

    public function group($chainUrlGroup = ''){
        $router = $this->get('Router') ?? throw new \RuntimeException(self::E_Router);
        return $router->group($chainUrlGroup);
    }


    public function reroute($url=NULL,$permanent=FALSE,$die=TRUE) {
        $router = $this->get('Router') ?? throw new \RuntimeException(self::E_Router);
        $router->reroute($url,$permanent,$die);
    }

    /**
     * Load config from YAML or delegate to Base::config()
     */
    public function config(string $file): void
    {        
        if (!file_exists($file)) {
            $this->error(500, "Config file not found: $file");
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
                        // Совместимо с set() и mset()
                        if (is_array($val)) {
                            $this->mset($val, $key . '.'); // вложенные ключи
                        } else {
                            $this->set($key, $val);
                        }
                    }
                } catch (\Exception $e) {
                    $this->error(500, "YAML parse error: " . $e->getMessage());
                }
                break;

            default:
                $this->error(500, "Unsupported config file extension: $ext");
        }
    }

    /**
     * Добавляет middleware
     * @param callable $handler
     * @return void
     */
    public function add(callable $handler)
    {
        $router = $this->get('Router') ?? throw new \RuntimeException(self::E_Router);
        $router->addMiddleware($handler);
    }

    /**
    *   Добавить событие в планировщик
    *   @return void
    **/
    public function schedule(callable $callback, string $expression = '* * * * *'): void
    {
        $scheduler = $this->g('Scheduler',new Scheduler());
        $scheduler->add($callback, $expression);
    }

    /**
    *   Обработка всех событий планировщика
    *   @return void
    **/
    public function runScheduledTasks(): void
    {
        $scheduler = $this->get('Scheduler');
        if (!empty($scheduler)) {
            $scheduler->run();
        }
    }

    /**
    *   Retrieve contents of hive key
    *   @return mixed
    *   @param $key string
    *   @param $args string|array
    **/
    public function g($key, $def = null)
    {
        $val = $this->ref($key, false);
        if (is_null($val)) {
            if (!is_null($def)) {
                return $def;
            } elseif (is_object($this->cache) && $this->cache_exists($this->hash($key).'.var', $data)) {
                return $data;
            }
        }
        return $val;
    }

    /**
    *   json answer
    *   @return Response
    **/
    public function json(Response $res): Response
    {
        $bodyArray = $res->makeBody();
        return $res->withHeader('Content-Type','application/json; charset=utf-8')->withBody(json_encode($bodyArray, JSON_UNESCAPED_UNICODE ));
    }
}

<?php

namespace App\Http;

use App\F4;
use App\Http\Response;
use App\Http\Request;

class Router {
    protected array $groups = [];
    protected ?RouterGroups $currentGroup = null;

    const
        VERBS='GET|HEAD|POST|PUT|PATCH|DELETE|CONNECT|OPTIONS';

    const
        E_Pattern='Invalid routing pattern: %s',
        E_Named='Named route does not exist: %s',
        E_Alias='Invalid named route alias: %s',
        E_Onreroute='Router ONREROUTE method busy',
        E_Handler = 'Invalid route handler. Use either [$className, $method] or a callable function.',
        E_Response='Invalid response: expected instanceof Response',
        E_Routes='No routes specified';

    const
        REQ_SYNC=1,
        REQ_AJAX=2,
        REQ_CLI=4;

    private $alias;
    private $aliases;
    private $routes;
    private const TYPES = ['sync','ajax','cli'];
    private $globalMiddleware;
    private F4 $f4;
    private Request $req;
    private Response $res;

    public function __construct(F4 $f4, Request $req, Response $res) {
        $this->f4 = $f4;
        $this->req = $req;
        $this->res = $res;
        $this->globalMiddleware = new MiddlewareDispatcher();
        $base = $f4->get('BASE');
        $uri = $req->getUri();
        /*if ($req->isCli() &&
            preg_match('/^'.preg_quote($base,'/').'$/',$uri))
            $this->reroute('/');*/
    }

    /**
    *   Replace tokenized URL with available token values
    *   @return string
    *   @param $url array|string
    *   @param $addParams boolean merge default PARAMS from hive into args
    *   @param $args array
    **/
    function build($url, $args=[], $addParams=TRUE) {
        $params = $this->f4->get('PARAMS');
        if ($addParams)
            $args+=$this->f4->recursive($params, function($val) {
                return implode('/', array_map('urlencode', explode('/', $val)));
            });
        if (is_array($url))
            foreach ($url as &$var) {
                $var=$this->build($var,$args, false);
                unset($var);
            }
        else {
            $i=0;
            $url=preg_replace_callback('/(\{)?@(\w+)(?(1)\})|(\*)/',
                function($match) use(&$i,$args) {
                    if (isset($match[2]) &&
                        array_key_exists($match[2],$args))
                        return $args[$match[2]];
                    if (isset($match[3]) &&
                        array_key_exists($match[3],$args)) {
                        if (!is_array($args[$match[3]]))
                            return $args[$match[3]];
                        ++$i;
                        return $args[$match[3]][$i-1];
                    }
                    return $match[0];
                },$url);
        }
        return $url;
    }

    /**
    *   Mock HTTP request ЗАГЛУШКА
    *   @return mixed
    *   @param $pattern string
    *   @param $args array
    *   @param $headers array
    *   @param $body string
    **/
    function dispatch(F4 $f4, Request $req, Response $res): Response {
        $this->f4 = $f4;
        $this->globalMiddleware = new MiddlewareDispatcher();
        $prevReq = $this->req; $prevRes = $this->res;
        try {
            $this->req = $req;
            $this->res = $res;
            return $this->run();
        } finally {
            $this->req = $prevReq;
            $this->res = $prevRes;
        }
    }

    /**
    *   Bind handler to route pattern
    *   @return NULL* 
    *   @param string|array $pattern
    *   @param array|callable $handler  // только [$className, $method] или callable
    *   @param int $ttl
    *   @param int $kbps
    **/
    public function route($pattern,$handler,$ttl=0,$kbps=0) {
        $alias=null;
        preg_match('/([\|\w]+)\h+(?:(?:@?(.+?)\h*:\h*)?(@(\w+)|[^\h]+))'.
            '(?:\h+\[('.implode('|',self::TYPES).')\])?/u',$pattern,$parts);
        if (isset($parts[2]) && $parts[2]) {
            if (!preg_match('/^\w+$/',$parts[2]))
                user_error(sprintf(self::E_Alias,$parts[2]),E_USER_ERROR);
            $ctx = $this->currentGroup();
            if ($ctx) {
                $parts[3] = Route::joinPath($ctx->chainPrefix(), $parts[3]);
            }
            $alias = $parts[2];
            $chain = ($ctx)?$ctx->chainPrefix():'';
            $this->aliases[$alias] = [
                'url' => $parts[3],
                'group' => $chain,
            ];
        }
        elseif (!empty($parts[4])) {
            if (empty($this->aliases[$parts[4]]))
            user_error(sprintf(self::E_Named,$parts[4]),E_USER_ERROR);
            $alias = $parts[4];
            $parts[3] = $this->aliases[$alias]['url'];
        }
        if (empty($parts[3]))
            user_error(sprintf(self::E_Pattern,$pattern),E_USER_ERROR);
        $type=empty($parts[5])?0:constant('self::REQ_'.strtoupper($parts[5]));
        $validArrayForm =
            is_array($handler)
            && count($handler) === 2
            && is_string($handler[0])
            && is_string($handler[1]);
        if (!is_callable($handler) && !$validArrayForm) {
            user_error(self::E_Handler, E_USER_ERROR);
        }

        $ctx = $this->currentGroup();
        if ($ctx && empty($parts[2])) {
            $parts[3] = Route::joinPath($ctx->chainPrefix(), $parts[3]);
        } 

        $routes = new RoutesCollection();
        $uri = $this->f4->get('URI');
        foreach ($this->f4->split($parts[1]) as $verb) {
            if (!preg_match('/'.self::VERBS.'/',$verb))
                $this->f4->error(501,$verb.' '.$uri);
            $chain = ($ctx)?$ctx->chainPrefix():'';

            $route = new Route([
                'handler'=>$handler,
                'ttl' => $ttl,
                'kbps' => $kbps,
                'alias' => $alias,
                'type' => $type,
                'verb' => strtoupper($verb),
                'group' => $chain
            ]);
            // навешиваем мидлварь группы на каждый роут
            if ($ctx) {
                $ctx->flagMW(); //Добавляем флаг что middleware нельзя больше добавлять
                foreach ($ctx->middlewares() as $mw) { $route->addMiddleware($mw); }
            }
            $this->routes[$parts[3]][$type][strtoupper($verb)] = &$route;
            $routes->addRoute($route);
        }
        return $routes;
    }

    public function group($chainUrlGroup = ''): RouterGroups
    {
        $this->currentGroup = new RouterGroups($this, $chainUrlGroup);
        $chain = $this->currentGroup->chainPrefix();
        if($chain && !in_array($chain, $this->groups) ){
            $this->groups[] = $chain;
        }
        return $this->currentGroup;
    }

    public function clearGroup(RouterGroups $g): void {
        if ($this->currentGroup === $g) {
            $this->currentGroup = null;
        }
    }

    public function currentGroup(): ?RouterGroups { return $this->currentGroup; }

    /**
    * Вспомогательный метод: смонтировать уже собранную коллекцию в роутер
    */
    public function mount(RoutesCollection $collection): void
    {
        foreach ($collection->all() as $route) {
            $this->registerRoute($route);
        }
    }

    public function addMiddleware(callable $mw) {
        $this->globalMiddleware->add($mw);
        return $this;
    }

    /**
    *   Assemble url from alias name
    *   @return string
    *   @param $name string
    *   @param $params array|string
    *   @param $query string|array
    *   @param $fragment string
    **/
    function alias($name,$params=[],$query=NULL,$fragment=NULL) {
        if (!is_array($params))
            $params=$this->f4->parse($params);
        if (empty($this->aliases[$name]))
            user_error(sprintf(self::E_Named,$name),E_USER_ERROR);
        $url=$this->build($this->aliases[$name]['url'],$params);
        if (is_array($query))
            $query=http_build_query($query);
        return $url.($query?('?'.$query):'').($fragment?'#'.$fragment:'');
    }

    /**
    *   Reroute to specified URI
    *   @return NULL
    *   @param $url array|string
    *   @param $permanent bool
    *   @param $die bool
    **/
    function reroute($url=NULL,$permanent=FALSE,$die=TRUE) {
        $req  = $this->req;
        $res  = $this->res;
        if (!$url)
            $url=$req->getUri();
        if (is_array($url))
            $url=call_user_func_array([$this,'alias'],$url);
        elseif (preg_match('/^(?:@([^\/()?#]+)(?:\((.+?)\))*(\?[^#]+)*(#.+)*)/',
            $url,$parts) && isset($this->aliases[$parts[1]]))
            $url=$this->build($this->aliases[$parts[1]]['url'],
                    isset($parts[2])?$this->f4->parse($parts[2]):[]).
                (isset($parts[3])?$parts[3]:'').(isset($parts[4])?$parts[4]:'');
        else
            $url=$this->build($url);

        if (($handler=$this->f4->get('ONREROUTE')) &&
            $this->f4->call($handler,[$url,$permanent,$die])!==FALSE)
            return;
        if ($url[0]!='/' && !preg_match('/^\w+:\/\//i',$url))
            $url='/'.$url;
        if ($url[0]=='/' && (empty($url[1]) || $url[1]!='/')) {
            $port=$req->getPort();
            $port=in_array($port,[80,443])?'':(':'.$port);
            $url=$req->getScheme().'://'.
                $req->getHost().$port.$this->f4->get('BASE').$url;
        }
        $cli = $this->f4->get('CLI');
        if ($cli)
            $this->mock('GET '.$url.' [cli]');
        else {
            header('Location: '.$url);
            $status = $permanent?301:302;
            $res->withStatus($status)->send();
            if ($die)
                die;
        }
    }

    /**
    *   Redirect a route to another URL
    *   @return NULL
    *   @param $pattern string|array
    *   @param $url string
    *   @param $permanent bool
    */
    function redirect($pattern,$url,$permanent=TRUE) {
        if (is_array($pattern)) {
            foreach ($pattern as $item)
                $this->redirect($item,$url,$permanent);
            return;
        }
        $this->route($pattern,function($fw) use($url,$permanent) {
            $fw->reroute($url,$permanent);
        });
    }

    /**
    *   Applies the specified URL mask and returns parameterized matches
    *   @return $args array
    *   @param $pattern string
    *   @param $url string|NULL
    **/
    function mask($pattern,$url=NULL) {
        if (!$url)
            $url=$this->f4->rel($this->f4->get('URI'));
        $case=$this->f4->get('CASELESS')?'i':'';
        $wild=preg_quote($pattern,'/');
        $i=0;
        while (is_int($pos=strpos($wild,'\*'))) {
            $wild=substr_replace($wild,'(?P<_'.$i.'>[^\?]*)',$pos,2);
            ++$i;
        }
        preg_match('/^'.
            preg_replace(
                '/((\\\{)?@(\w+\b)(?(2)\\\}))/',
                '(?P<\3>[^\/\?]+)',
                $wild).'\/?$/'.$case.'um',$url,$args);
        foreach (array_keys($args) as $key) {
            if (preg_match('/^_\d+$/',$key)) {
                if (empty($args['*']))
                    $args['*']=$args[$key];
                else {
                    if (is_string($args['*']))
                        $args['*']=[$args['*']];
                    array_push($args['*'],$args[$key]);
                }
                unset($args[$key]);
            }
            elseif (is_numeric($key) && $key)
                unset($args[$key]);
        }
        return $args;
    }

    /**
    *   Match routes against incoming URI
    *   @return mixed
    **/
    function run() {
        $f4   = $this->f4;
        $req  = $this->req;
        $res  = $this->res;
        $verb   = $req->getMethod();
        $path   = $req->getPath();
        $query  = $req->getQueryStr() ?? '';
        $uri    = $req->getUri();
        $cli    = $req->isCli();
        $origin = $req->getHeader('Origin');
        $acrm   = $req->getHeader('Access-Control-Request-Method');
        $cors   = $f4->get('CORS');
        if (!$this->routes)
            // No routes defined
            user_error(self::E_Routes,E_USER_ERROR);
        // Match specific routes first
        $paths=[];
        foreach ($keys=array_keys($this->routes) as $key) {
            $p=preg_replace('/@\w+/','*@',$key);
            if (substr($p,-1)!='*')
                $p.='+';
            $paths[]=$p;
        }
        $vals=array_values($this->routes);
        array_multisort($paths,SORT_DESC,$keys,$vals);
        $this->routes=array_combine($keys,$vals);
        // Convert to BASE-relative URL
        $req_url=urldecode($path);
        $preflight=FALSE;
        if ($cors = ($origin && $cors['origin'])) {
            $res = $res
                ->withHeader('Access-Control-Allow-Origin', $cors['origin'])
                ->withHeader('Access-Control-Allow-Credentials', $f4->export($cors['credentials']));
            $preflight = (bool)$acrm;
        }
        $allowed=[];
        foreach ($this->routes as $pattern=>$routes) {
            $args=$this->mask($pattern,$req_url);
            if (!$args=$this->mask($pattern,$req_url))
                continue;
            ksort($args);
            $route=NULL;
            $ptr=$cli?self::REQ_CLI:$req->isAjax()+1;
            if (isset($routes[$ptr][$verb]) ||
                ($preflight && isset($routes[$ptr])) ||
                isset($routes[$ptr=0]))
                $route=$routes[$ptr];
            if (!$route)
                continue;
            if (isset($route[$verb]) && !$preflight) {
                if ($f4->get('REROUTE_TRAILING_SLASH')===TRUE &&
                    $verb=='GET' &&
                    preg_match('/.+\/$/',$path))
                    $this->reroute(substr($path,0,-1).
                        ($query?('?'.$query):''));
                $fullMiddleware = clone $this->globalMiddleware;
                $cur_route = $route[$verb];
                foreach ($cur_route->middleware->all() as $mw) {
                    $fullMiddleware->add($mw);
                }
                $p = $cur_route->getParams();
                list($handler,$ttl,$kbps,$alias)=[$p['handler'],$p['ttl'],$p['kbps'],$p['alias']];
                // Capture values of route pattern tokens
                $f4->set('PARAMS',$args);
                // Save matching route
                $f4->set('ALIAS',$alias);
                $f4->set('PATTERN',$pattern);
                if ($cors && $cors['expose']) {
                    $res = $res->withHeader(
                        'Access-Control-Expose-Headers',
                        is_array($cors['expose']) ? implode(',', $cors['expose']) : $cors['expose']
                    );
                }
                // Process request
                $result=NULL;
                $body='';
                $now=microtime(TRUE);
                if (preg_match('/GET|HEAD/',$verb) && $ttl) {
                    // Only GET and HEAD requests are cacheable
                    $cached=$f4->cache_exists(
                        $hash=$f4->hash($verb.' '.
                            $uri).'.url',$data);
                    if ($cached) {
                        $mod_since = $req->getHeader('If-Modified-Since');
                        if (isset($mod_since) &&
                            strtotime($mod_since)+
                                $ttl>$now) {
                            $status = 304;
                            $res->withStatus($status)->send();
                            die;
                        }
                        // Retrieve from cache backend
                        list($headers,$body,$result)=$data;
                        user_error('Кэш страниц нужно переделывать проблема с пробросом заголовков и вообще он не доделан', E_USER_ERROR);
                        if (!$cli)
                            array_walk($headers,'header');
                        $res = $f4->expire($req, $res, $cached[0]+$ttl-$now);
                    }
                    else
                        // Expire HTTP client-cached page
                        $res = $f4->expire($req, $res, $ttl);
                }
                else
                    $res = $f4->expire($req, $res, 0);
                if (!strlen($body)) {
                    ob_start();
                    $final = function () use ($args, $handler, $f4, $req, &$res) {
                        // Новый контракт контроллеров: ($req, $res, $params) 
                        return $this->invokeHandler($handler, $req, $res, $args);
                    };
                    $result = $fullMiddleware->dispatch($req, $res, $args, $final);
                    $body = ob_get_clean(); // должен быть пустым
                    if ($result instanceof Response) {
                        $body .= $result->getBody();
                    } else {
                        user_error(self::E_Response, E_USER_ERROR);
                    }
                    
                    if (isset($cache) && !error_get_last()) {
                        // Save to cache backend
                        $f4->cache_set($hash,[
                            // Remove cookies
                            preg_grep('/Set-Cookie\:/',headers_list(),
                                PREG_GREP_INVERT),$body,$result],$ttl);
                    }
                }
                $f4->set('RESPONSE', $body);
                if (!$f4->get('QUIET')) {
                    if ($kbps) {
                        // Если нужен троттлинг — выводим частями
                        // kbps = KB/s
                        $bytesPerSec = max(1, (int)$kbps) * 1024;
                        $chunk = 8192;
                        $sent=0; 
                        $now=microtime(true);
                        $buffer = '';
                        $len = strlen($body);
                        $this->res = $res->withBody(''); // пустое тело, будем писать вручную
                        $this->res->send($cli);   // если у тебя есть отдельная отправка заголовков
                        for ($off = 0; $off < $len; $off += $chunk) {
                            if (connection_aborted()) break;
                            $part = substr($body, $off, $chunk);
                            $buffer .= $part;

                            echo $part;
                            if (function_exists('ob_flush')) ob_flush();
                            flush();

                            $sent += strlen($part);
                            $expected = $sent/$bytesPerSec;
                            if ($expected > ($elapsed=microtime(true)-$now)) {
                                usleep((int)round(1e6*($expected-$elapsed)));
                            }
                        }
                        return;
                    } else {
                        $res = $res->withBody($body);
                    }

                }
                $this->res = $res;
                $this->res->send($cli);      
        
                if ($result || $verb!='OPTIONS')
                    return $result;
            }
            $allowed=array_merge($allowed,array_keys($route));
        }
        if (!$allowed){
            // URL doesn't match any route
            $f4->error(404);
        } elseif (!$cli) {
            if (!preg_grep('/Allow:/',$headers_send=headers_list()))
                // Unhandled HTTP method
                $res = $res->withHeader('Allow', implode(',', array_unique($allowed)));
            if ($cors) {
                $res = $res->withHeader('Access-Control-Allow-Methods', 'OPTIONS,'.implode(',', $allowed));
                if ($cors['headers']) {
                    $res = $res->withHeader('Access-Control-Allow-Headers',
                        is_array($cors['headers']) ? implode(',', $cors['headers']) : $cors['headers']);
                }
                if ($cors['ttl']) {
                    $res = $res->withHeader('Access-Control-Max-Age', (string)$cors['ttl']);
                }
            }
            if ($verb!='OPTIONS')
                $f4->error(405);
        }
        return FALSE;
    }

    /**
    *   Return TRUE if IPv4 address exists in DNSBL
    *   @return bool
    *   @param $ip string
    **/
    function blacklisted($ip) {
        $f4 = $this->f4;
        $exempt = $f4->get('EXEMPT');
        $dnsbl = $f4->get('DNSBL');
        if ($dnsbl &&
            !in_array($ip,
                is_array($exempt)?
                    $exempt:
                    $f4->split($exempt))) {
            // Reverse IPv4 dotted quad
            $rev=implode('.',array_reverse(explode('.',$ip)));
            foreach (is_array($dnsbl)?
                $dnsbl:
                $f4->split($dnsbl) as $server)
                // DNSBL lookup
                if (checkdnsrr($rev.'.'.$server,'A'))
                    return TRUE;
        }
        return FALSE;
    }

    function addOnReroute(callable $handler){
        if(!empty($this->hive['ONREROUTE']))
            user_error(self::E_Onreroute,E_USER_ERROR);
        $this->hive['ONREROUTE'] = $handler;
    }

    // Внутри class Router
    private function invokeHandler($handler, $req, Response $res, array $params): Response
    {
        // 1) Callable-функции/замыкания
        if (is_callable($handler) && !is_array($handler)) {
            $out = call_user_func($handler, $req, $res, $params);
            // Разрешаем экшенам возвращать либо Response, либо строку/скаляр
            if ($out instanceof Response) {
                return $out;
            } elseif (is_string($out)) {
                return $res->withBody($out);
            } else {
                user_error(self::E_Response, E_USER_ERROR);
            }
        }

        // 2) [$classOrObj, 'method']
        if (is_array($handler) && count($handler) === 2) {
            [$target, $method] = $handler;

            if (is_string($target)) {
                $target = $this->f4->resolveFromContainer($target);
            }

            // Жизненный цикл: beforeAction → action → afterAction
            if (method_exists($target, 'beforeRoute') && is_callable([$target, 'beforeRoute'])) {
                $ok = $target->beforeRoute($req, $res, $params);
                if ($ok === false) {
                    return $res; // прерываем обработку
                }
            }

            $out = call_user_func([$target, $method], $req, $res, $params);

            if (method_exists($target, 'afterRoute') && is_callable([$target, 'afterRoute'])) {
                $target->afterRoute($req, $res, $params);
            }

            if ($out instanceof Response) {
                return $out;
            } else if (is_string($out)) {
                return $res->withBody($out);
            }
            user_error(self::E_Response, E_USER_ERROR);
        }

        // Неподдерживаемый формат
        user_error('Invalid handler: expected callable or [$class,$method]', E_USER_ERROR);
        return $res;
    }

}
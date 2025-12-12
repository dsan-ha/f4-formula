<?

namespace App\Base;

use App\Http\Response;
use App\Http\Request;
use App\Http\Environment;

trait F3Tools {    
    //@{ HTTP status codes (RFC 2616)
    const
        HTTP_100='Continue',
        HTTP_101='Switching Protocols',
        HTTP_103='Early Hints',
        HTTP_200='OK',
        HTTP_201='Created',
        HTTP_202='Accepted',
        HTTP_203='Non-Authorative Information',
        HTTP_204='No Content',
        HTTP_205='Reset Content',
        HTTP_206='Partial Content',
        HTTP_300='Multiple Choices',
        HTTP_301='Moved Permanently',
        HTTP_302='Found',
        HTTP_303='See Other',
        HTTP_304='Not Modified',
        HTTP_305='Use Proxy',
        HTTP_307='Temporary Redirect',
        HTTP_308='Permanent Redirect',
        HTTP_400='Bad Request',
        HTTP_401='Unauthorized',
        HTTP_402='Payment Required',
        HTTP_403='Forbidden',
        HTTP_404='Not Found',
        HTTP_405='Method Not Allowed',
        HTTP_406='Not Acceptable',
        HTTP_407='Proxy Authentication Required',
        HTTP_408='Request Timeout',
        HTTP_409='Conflict',
        HTTP_410='Gone',
        HTTP_411='Length Required',
        HTTP_412='Precondition Failed',
        HTTP_413='Request Entity Too Large',
        HTTP_414='Request-URI Too Long',
        HTTP_415='Unsupported Media Type',
        HTTP_416='Requested Range Not Satisfiable',
        HTTP_417='Expectation Failed',
        HTTP_421='Misdirected Request',
        HTTP_422='Unprocessable Entity',
        HTTP_423='Locked',
        HTTP_429='Too Many Requests',
        HTTP_451='Unavailable For Legal Reasons',
        HTTP_500='Internal Server Error',
        HTTP_501='Not Implemented',
        HTTP_502='Bad Gateway',
        HTTP_503='Service Unavailable',
        HTTP_504='Gateway Timeout',
        HTTP_505='HTTP Version Not Supported',
        HTTP_507='Insufficient Storage',
        HTTP_511='Network Authentication Required';
    //@}

    const
        //! Cache folder
        Cache_folder='base',
        //! Verb list http
        VERBS='GET|HEAD|POST|PUT|PATCH|DELETE|CONNECT|OPTIONS',
        //! Mapped PHP globals
        GLOBALS='GET|POST|COOKIE|REQUEST|SESSION|FILES|SERVER|ENV',
        //! Default directory permissions
        MODE=0755,
        //! Syntax highlighting stylesheet
        CSS='ui/css/code.css';

    //@{ Error messages
    const
        E_Pattern='Invalid routing pattern: %s',
        E_Named='Named route does not exist: %s',
        E_Alias='Invalid named route alias: %s',
        E_Fatal='Fatal error: %s',
        E_Open='Unable to open %s',
        E_Routes='No routes specified',
        E_Router='Router not initialized',
        E_Class='Invalid class %s',
        E_Container='DI container not initialized',
        E_Method='Invalid method %s',
        E_Hive='Invalid hive key %s';
    //@}
        
    /**
     * @var F4|null — синглтон-обёртка
     */
    protected static $instance = null;

    private
        //! Globals
        $hive,
        //! Mutex locks
        $locks=[],
        $cache;

    /**
     * Возвращает инстанс F4-обёртки (синглтон)
     * @return F4
     */
    public static function instance()
    {
        if (self::$instance === null) {
            $f4 = new self();
            self::$instance = $f4;
            $f4->bootstrap();
        }
        return self::$instance;
    }

    function init()
    {
        $this->set('Router',$this->getDI(\App\Http\Router::class));
        $this->cache = $this->getDI(\App\Utils\Cache::class);
        $this->set('EventManager',$this->getDI(\App\Events\EventManager::class));
        $this->set('SessionService', $this->getDI(\App\Base\SessionService::class));
        $this->set('CookieService',  $this->getDI(\App\Base\CookieService::class));
        if ($this->exists('JAR', $jar) && is_array($jar)) {
            $this->get('CookieService')->configure($jar);
        }
    }

    function cache_exists(string $key, &$value = null)
    {
        return $this->cache->exists($key, self::Cache_folder, $value);
    }

    function cache_set(string $key, $value, int $ttl = 0)
    {
        $this->cache->set($key, self::Cache_folder, $value, $ttl);
    }

    function cache_get(string $key, $def = '')
    {
        return $this->cache->get($key, self::Cache_folder, $def);
    }

    function cache_clear(string $key)
    {
        return $this->cache->clear($key, self::Cache_folder);
    }

    function hasDI(string $id): bool {
        if((bool)!$this->exists('CONTAINER', $c)) return false;
        return $c->has($id);
    }

    function getDI(string $id) {
        if ((bool)!$this->exists('CONTAINER', $c)) {
            throw new \RuntimeException('DI container not initialized');
        }
        return $c->get($id);
    }

    /**
    *   Return the parts of specified hive key
    *   @return array
    *   @param $key string
    **/
    private function cut($key) {
        return preg_split('/\[\h*[\'"]?(.+?)[\'"]?\h*\]|(->)|\./',
            $key,-1,PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
    }

    /**
    *   Parse string containing key-value pairs
    *   @return array
    *   @param $str string
    **/
    function parse($str) {
        preg_match_all('/(\w+|\*)\h*=\h*(?:\[(.+?)\]|(.+?))(?=,|$)/',
            $str,$pairs,PREG_SET_ORDER);
        $out=[];
        foreach ($pairs as $pair)
            if ($pair[2]) {
                $out[$pair[1]]=[];
                foreach (explode(',',$pair[2]) as $val)
                    array_push($out[$pair[1]],$val);
            }
            else
                $out[$pair[1]]=trim($pair[3]);
        return $out;
    }

    /**
    *   Get hive key reference/contents; Add non-existent hive keys,
    *   array elements, and object properties by default
    *   @return mixed
    *   @param $key string
    *   @param $add bool
    *   @param $var mixed
    **/
    function &ref($key, $add = TRUE, &$var = NULL) {
        $null = NULL;
        $parts = $this->cut($key);
        if (!preg_match('/^\w+$/', $parts[0]))
            user_error(sprintf(self::E_Hive, $this->stringify($key)), E_USER_ERROR);
        
        if (is_null($var)) {
            if ($add)
                $var = &$this->hive;
            else
                $var = $this->hive;
        }
        
        $prev_delimiter = null;
        foreach ($parts as $part) {
            if ($part == '->' || $part == '.') {
                $prev_delimiter = $part;
                continue;
            }
            
            // Определяем тип доступа на основе разделителя и текущего типа данных
            if ($prev_delimiter == '->') {
                // Явный доступ к объекту ->
                if (!is_object($var)) {
                    if ($add) {
                        $var = new \stdClass;
                    } else {
                        $var = &$null;
                        break;
                    }
                }
                if ($add || property_exists($var, $part)) {
                    $var = &$var->$part;
                } else {
                    $var = &$null;
                    break;
                }
            } elseif ($prev_delimiter == '.') {
                // Точка - доступ зависит от текущего типа данных
                if (is_object($var)) {
                    // Если текущий элемент - объект, то точка = свойство объекта
                    if ($add || property_exists($var, $part)) {
                        $var = &$var->$part;
                    } else {
                        $var = &$null;
                        break;
                    }
                } else {
                    // Если текущий элемент не объект, то точка = массив
                    if (!is_array($var)) {
                        if ($add) {
                            $var = [];
                        } else {
                            $var = &$null;
                            break;
                        }
                    }
                    if ($add || array_key_exists($part, $var)) {
                        $var = &$var[$part];
                    } else {
                        $var = &$null;
                        break;
                    }
                }
            } else {
                // Первый элемент или без предыдущего разделителя - по умолчанию массив
                if (!is_array($var)) {
                    if ($add) {
                        $var = [];
                    } else {
                        $var = &$null;
                        break;
                    }
                }
                if ($add || array_key_exists($part, $var)) {
                    $var = &$var[$part];
                } else {
                    $var = &$null;
                    break;
                }
            }
            
            $prev_delimiter = null;
        }
        
        return $var;
    }

    /**
    *   Return TRUE if hive key is set
    *   (or return timestamp and TTL if cached)
    *   @return bool
    *   @param $key string
    *   @param $val mixed
    **/
    function exists($key,&$val=NULL) {
        $val=$this->ref($key,FALSE);
        return isset($val)?
            TRUE:
            is_object($this->cache) && ($this->cache->exists($this->hash($key).'.var',self::Cache_folder,$val)?:FALSE);
    }

    /**
    *   Bind value to hive key
    *   @return mixed
    *   @param $key string
    *   @param $val mixed
    *   @param $ttl int
    **/
    function set($key,$val,$ttl=0) {
        $time=(int)$this->hive['TIME'];
        if (preg_match('/^COOKIE\b/',$key)) {
            // COOKIE.<name>
            $parts = $this->cut($key);
            $name  = $parts[1] ?? null;
            if ($name) {
                /** @var \App\Base\CookieService $cookies */
                $cookies = $this->get('CookieService');
                $cookies->set($name, $val ?: '', (int)$ttl);
            }
            return $val;
            
        }
        else switch ($key) {
        case 'ENCODING':
            ini_set('default_charset',$val);
            if (extension_loaded('mbstring'))
                mb_internal_encoding($val);
            break;
        case 'TZ':
            date_default_timezone_set($val);
            break;
        }
        $ref=&$this->ref($key);
        $ref=$val;
        if ($ttl && is_object($this->cache))
            // Persist the key-value pair
            $this->cache->set($this->hash($key).'.var',self::Cache_folder,$val,$ttl);
        return $ref;
    }

    /**
    *   Retrieve contents of hive key
    *   @return mixed
    *   @param $key string
    *   @param $args string|array
    **/
    function get($key,$args=NULL) {
        if (is_string($val=$this->ref($key,FALSE)) && !is_null($args))
            return call_user_func_array(
                [$this,'format'],
                array_merge([$val],is_array($args)?$args:[$args])
            );
        if (is_null($val)) {
            // Attempt to retrieve from cache
            if (is_object($this->cache) && $this->cache->exists($this->hash($key).'.var',self::Cache_folder,$data))
                return $data;
        }
        return $val;
    }

    /**
    *   Extract values of array whose keys start with the given prefix
    *   @return array
    *   @param $arr array
    *   @param $prefix string
    **/
    function extract($arr,$prefix) {
        $out=[];
        foreach (preg_grep('/^'.preg_quote($prefix,'/').'/',array_keys($arr))
            as $key)
            $out[substr($key,strlen($prefix))]=$arr[$key];
        return $out;
    }

    /**
    *   Unset hive key
    *   @param $key string
    **/
    function clear($key) {
        // Normalize array literal
        $cache=$this->cache;
        $parts=$this->cut($key);
        if ($key=='CACHE' && is_object($cache))
            // Clear cache contents
            $cache->clearFolder(self::Cache_folder);
        elseif (preg_match('/^COOKIE\b/',$key)) {
            $parts = $this->cut($key);
            $name  = $parts[1] ?? null;
            if ($name) {
                /** @var \App\Base\CookieService $cookies */
                $cookies = $this->get('CookieService');
                $cookies->clear($name);
            }
            unset($_COOKIE[$name]);
            
        }
        
        
        if(isset($this->hive[$key])) unset($this->hive[$key]);
        if ($parts[0]=='SESSION') {
            session_commit();
            session_start();
        }
        if (is_object($cache) && $cache->exists($hash=$this->hash($key).'.var',self::Cache_folder))
            // Remove from cache
            $cache->clear($hash,self::Cache_folder);
        
    }

    /**
     *   
     * Нормализация после json_decode
     */
    function normalizeJsonTypes($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->normalizeJsonTypes($value);
            }
        } elseif (is_string($data)) {
            $lower = strtolower($data);
            if ($lower === 'true') 
                return true;
            elseif ($lower === 'false')
                return false;
            elseif ($lower === 'null')
                return null;
            elseif (is_numeric($data)) {
                return strpos($data, '.') !== false ? (float)$data : (int)$data;
            }
        }
        return $data;
    }

    /**
    *   Return TRUE if property has public visibility
    *   @return bool
    *   @param $obj object
    *   @param $key string
    **/
    function visible($obj,$key) {
        if (property_exists($obj,$key)) {
            $ref=new \ReflectionProperty(get_class($obj),$key);
            $out=$ref->isPublic();
            unset($ref);
            return $out;
        }
        return FALSE;
    }


    /**
    *   Multi-variable assignment using associative array
    *   @param $vars array
    *   @param $prefix string
    *   @param $ttl int
    **/
    function mset(array $vars,$prefix='',$ttl=0) {
        foreach ($vars as $key=>$val)
            $this->set($prefix.$key,$val,$ttl);
    }

    /**
    *   Publish hive contents
    *   @return array
    **/
    function hive() {
        return $this->hive;
    }

    /**
    *   Extend hive array variable with default values from $src
    *   @return array
    *   @param $key string
    *   @param $src string|array
    *   @param $keep bool
    **/
    function extend($key,$src,$keep=FALSE) {
        $ref=&$this->ref($key);
        if (!$ref)
            $ref=[];
        $out=array_replace_recursive(
            is_string($src)?$this->hive[$src]:$src,$ref);
        if ($keep)
            $ref=$out;
        return $out;
    }

    /**
    *   Convert backslashes to slashes
    *   @return string
    *   @param $str string
    **/
    function fixslashes($str) {
        return $str?strtr($str,'\\','/'):$str;
    }

    /**
    *   Split comma-, semi-colon, or pipe-separated string
    *   @return array
    *   @param $str string
    *   @param $noempty bool
    **/
    function split($str,$noempty=TRUE) {
        return array_map('trim',
            preg_split('/[,;|]/',$str?:'',0,$noempty?PREG_SPLIT_NO_EMPTY:0));
    }

    /**
    *   Convert PHP expression/value to compressed exportable string
    *   @return string
    *   @param $arg mixed
    *   @param $stack array
    **/
    function stringify($arg,?array $stack=NULL) {
        if ($stack) {
            foreach ($stack as $node)
                if ($arg===$node)
                    return '*RECURSION*';
        }
        else
            $stack=[];
        switch (gettype($arg)) {
            case 'object':
                $str='';
                foreach (get_object_vars($arg) as $key=>$val)
                    $str.=($str?',':'').
                        $this->export($key).'=>'.
                        $this->stringify($val,
                            array_merge($stack,[$arg]));
                return get_class($arg).'::__set_state(['.$str.'])';
            case 'array':
                $str='';
                $num=isset($arg[0]) &&
                    ctype_digit(implode('',array_keys($arg)));
                foreach ($arg as $key=>$val)
                    $str.=($str?',':'').
                        ($num?'':($this->export($key).'=>')).
                        $this->stringify($val,array_merge($stack,[$arg]));
                return '['.$str.']';
            default:
                return $this->export($arg);
        }
    }

    /**
    *   Flatten array values and return as CSV string
    *   @return string
    *   @param $args array
    **/
    function csv(array $args) {
        return implode(',',array_map('stripcslashes',
            array_map([$this,'stringify'],$args)));
    }


    /**
    *   Return locale-aware formatted string
    *   @return string
    **/
    function format(string $tpl, ...$args) {
        return vsprintf($tpl, $args);
    }

    /**
    *   Return string representation of expression
    *   @return string
    *   @param $expr mixed
    **/
    function export($expr) {
        return var_export($expr,TRUE);
    }


    /**
    *   Convert class constants to array
    *   @return array
    *   @param $class object|string
    *   @param $prefix string
    **/
    function constants($class,$prefix='') {
        $ref=new \ReflectionClass($class);
        return $this->extract($ref->getconstants(),$prefix);
    }

    /**
    *   Generate 64bit/base36 hash
    *   @return string
    *   @param $str
    **/
    function hash($str) {
        return str_pad(base_convert(
            substr(sha1($str?:''),-16),16,36),11,'0',STR_PAD_LEFT);
    }

    /**
    *   Convert special characters to HTML entities
    *   @return string
    *   @param $str string
    **/
    function encode($str) {
        return @htmlspecialchars($str,$this->hive['BITMASK'],
            $this->hive['ENCODING'])?:$this->scrub($str);
    }

    /**
    *   Convert HTML entities back to characters
    *   @return string
    *   @param $str string
    **/
    function decode($str) {
        return htmlspecialchars_decode($str,$this->hive['BITMASK']);
    }

    /**
    *   Invoke callback recursively for all data types
    *   @return mixed
    *   @param $arg mixed
    *   @param $func callback
    *   @param $stack array
    **/
    function recursive($arg,$func,$stack=[]) {
        if ($stack) {
            foreach ($stack as $node)
                if ($arg===$node)
                    return $arg;
        }
        switch (gettype($arg)) {
            case 'object':
                $ref=new \ReflectionClass($arg);
                if ($ref->iscloneable()) {
                    $arg=clone($arg);
                    $cast=($it=is_a($arg,'IteratorAggregate'))?
                        iterator_to_array($arg):get_object_vars($arg);
                    foreach ($cast as $key=>$val) {
                        // skip inaccessible properties #350
                        if (!$it && !isset($arg->$key))
                            continue;
                        $arg->$key=$this->recursive(
                            $val,$func,array_merge($stack,[$arg]));
                    }
                }
                return $arg;
            case 'array':
                $copy=[];
                foreach ($arg as $key=>$val)
                    $copy[$key]=$this->recursive($val,$func,
                        array_merge($stack,[$arg]));
                return $copy;
        }
        return $func($arg);
    }

    /**
    *   Similar to clean(), except that variable is passed by reference
    *   @return mixed
    *   @param $var mixed
    *   @param $tags string
    **/
    function scrub(&$var,$tags=NULL) {
        return $var=$this->clean($var,$tags);
    }

    /**
    *   Return string representation of PHP value
    *   @return string
    *   @param $arg mixed
    **/
    function serialize($arg) {
        switch (strtolower($this->hive['SERIALIZER'])) {
            case 'igbinary':
                return igbinary_serialize($arg);
            default:
                return serialize($arg);
        }
    }

    /**
    *   Return PHP value derived from string
    *   @return string
    *   @param $arg mixed
    **/
    function unserialize($arg) {
        switch (strtolower($this->hive['SERIALIZER'])) {
            case 'igbinary':
                return igbinary_unserialize($arg);
            default:
                return unserialize($arg);
        }
    }

    /**
    *   Send HTTP status header; Return text equivalent of status code
    *   @return string
    *   @param $code int
    **/
    function status($code, $res=null, $send = false) {
        $reason=@constant('self::HTTP_'.$code);
        if (!$this->hive['CLI'] && !headers_sent())
            header($_SERVER['SERVER_PROTOCOL'].' '.$code.' '.$reason);

        return $reason;
    }

    /**
    *   Send cache metadata to HTTP client
    *   @param $secs int
    **/
    function expire(Request $req, Response $res, int $secs = 0): Response
    {
        if ($req->isCli()) {
            return $res; // в CLI заголовки не имеют смысла
        }

        $now = time();

        // Базовые защитные заголовки (оставляем, если уже проставлены где-то выше)
        if (!$res->hasHeader('X-Frame-Options')) {
            $res = $res->withHeader('X-Frame-Options', 'SAMEORIGIN');
        }
        if (!$res->hasHeader('X-Content-Type-Options')) {
            $res = $res->withHeader('X-Content-Type-Options', 'nosniff');
        }
        if (!$res->hasHeader('X-XSS-Protection')) {
            // Да, заголовок устарел, но для обратной совместимости можно оставить
            $res = $res->withHeader('X-XSS-Protection', '1; mode=block');
        }

        $method = $req->getMethod();
        $cacheable = ($secs > 0) && ($method === 'GET' || $method === 'HEAD');

        if ($cacheable) {
            // Убираем Pragma если есть поддержка withoutHeader()
            if (method_exists($res, 'withoutHeader')) {
                $res = $res->withoutHeader('Pragma');
            }

            $res = $res
                ->withHeader('Cache-Control', 'max-age=' . $secs)
                ->withHeader('Expires', gmdate('D, d M Y H:i:s', $now + $secs) . ' GMT');

            if (!$res->hasHeader('Last-Modified')) {
                $res = $res->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $now) . ' GMT');
            }
        } else {
            $res = $res
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->withHeader('Expires', 'Fri, 01 Jan 1990 00:00:00 GMT');
        }

        return $res;
    }

    /**
    *   Return filtered stack trace as a formatted string (or array)
    *   @return string|array
    *   @param $trace array|NULL
    *   @param $format bool
    **/
    function trace(?array $trace=NULL,$format=TRUE) {
        if (!$trace) {
            $trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $frame=$trace[0];
            if (isset($frame['file']) && $frame['file']==__FILE__)
                array_shift($trace);
        }
        $debug=$this->hive['DEBUG'];
        $trace=array_filter(
            $trace,
            function($frame) use($debug) {
                return isset($frame['file']) &&
                    ($debug>1 ||
                    (($frame['file']!=__FILE__ || $debug) &&
                    (empty($frame['function']) ||
                    !preg_match('/^(?:(?:trigger|user)_error|'.
                        '__call|call_user_func)/',$frame['function']))));
            }
        );
        if (!$format)
            return $trace;
        $out='';
        $eol="\n";
        // Analyze stack trace
        foreach ($trace as $frame) {
            $line='';
            if (isset($frame['class']))
                $line.=$frame['class'].$frame['type'];
            if (isset($frame['function']))
                $line.=$frame['function'].'('.
                    ($debug>2 && isset($frame['args'])?
                        $this->csv($frame['args']):'').')';
            $src=$this->fixslashes(str_replace($_SERVER['DOCUMENT_ROOT'].
                '/','',$frame['file'])).':'.$frame['line'];
            $out.='['.$src.'] '.$line.$eol;
        }
        return $out;
    }

    /**
    *   Log error; Execute ONERROR handler if defined, else display
    *   default error page (HTML for synchronous requests, JSON string
    *   for AJAX requests)
    *   @param $code int
    *   @param $text string
    *   @param $trace array
    *   @param $level int
    **/
    function error($code,$text='',?array $trace=NULL,$level=0) {
        $header = @constant('self::HTTP_'.$code);
        $request = Environment::instance()->getRequest();
        $prior=$this->hive['ERROR'];
        http_response_code($code);
        $req=$request->getMethod().' '.$request->getPath();
        $query=$request->getQueryStr();
        if ($query)
            $req.='?'.$query;
        if (!$text)
            $text='HTTP '.$code.' ('.$req.')';
        $trace=$this->trace($trace);
        $loggable=$this->hive['LOGGABLE'];
        if (!is_array($loggable))
            $loggable=$this->split($loggable);
        foreach ($loggable as $status)
            if ($status=='*' ||
                preg_match('/^'.preg_replace('/\D/','\d',$status).'$/',(string) $code)) {
                error_log($text);
                foreach (explode("\n",$trace) as $nexus)
                    if ($nexus)
                        error_log($nexus);
                break;
            }

        if ($highlight=(!$request->isCli() && !$request->isAjax() &&
            $this->hive['HIGHLIGHT'] && is_file($css=SITE_ROOT.self::CSS)))
            $trace=$this->highlight($trace);
        $this->hive['ERROR']=[
            'status'=>$header,
            'code'=>$code,
            'text'=>$text,
            'trace'=>$trace,
            'level'=>$level
        ];
        //$res = $this->expire($request, $response, -1);
        $handler=$this->hive['ONERROR'];
        $this->hive['ONERROR']=NULL;
        $eol="\n";
        if ((!$handler ||
            $this->call($handler,[$this,$this->hive['PARAMS']])===FALSE) &&
            !$prior && !$this->hive['QUIET']) {
            $error=array_diff_key(
                $this->hive['ERROR'],
                $this->hive['DEBUG']?
                    []:
                    ['trace'=>1]
            );
            if ($request->isCli())
                $body = PHP_EOL.'==================================='.PHP_EOL.
                    'ERROR '.$error['code'].' - '.$error['status'].PHP_EOL.
                    $error['text'].PHP_EOL.PHP_EOL.(isset($error['trace']) ? $error['trace'] : '');
            else
                if($request->isAjax()){
                    $error['trace'] = explode("\n",$error['trace']);
                    $body = json_encode($error);
                } else {
                    $body = ('<!DOCTYPE html>'.$eol.
                    '<html>'.$eol.
                    '<head>'.
                        '<title>'.$code.' '.$header.'</title>'.
                        ($highlight?
                            ('<style>'.$this->read($css).'</style>'):'').
                    '</head>'.$eol.
                    '<body>'.$eol.
                        '<h1>'.$header.'</h1>'.$eol.
                        '<p>'.$this->encode($text?:$req).'</p>'.$eol.
                        ($this->hive['DEBUG']?('<pre>'.$trace.'</pre>'.$eol):'').
                    '</body>'.$eol.
                    '</html>');
                }
                
            echo $body;
        }
        if ($this->hive['HALT'])
            die(1);
    }
    
    /**
    *   Disconnect HTTP client;
    *   Set FcgidOutputBufferSize to zero if server uses mod_fcgid;
    *   Disable mod_deflate when rendering text/html output
    **/
    function abort() {
        if (!headers_sent() && session_status()!=PHP_SESSION_ACTIVE)
            session_start();
        $out='';
        while (ob_get_level())
            $out=ob_get_clean().$out;
        if (!headers_sent()) {
            header('Content-Length: '.strlen($out));
            header('Connection: close');
        }
        session_commit();
        echo $out;
        flush();
        if (function_exists('fastcgi_finish_request'))
            fastcgi_finish_request();
    }

    /**
    *   Loop until callback returns TRUE (for long polling)
    *   @return mixed
    *   @param $func callback
    *   @param $args array
    *   @param $timeout int
    **/
    function until($func,$args=NULL,$timeout=60) {
        if (!$args)
            $args=[];
        $time=time();
        $max=ini_get('max_execution_time');
        $limit=max(0,($max?min($timeout,$max):$timeout)-1);
        $out='';
        // Turn output buffering on
        ob_start();
        // Not for the weak of heart
        while (
            // No error occurred
            !$this->hive['ERROR'] &&
            // Got time left?
            time()-$time+1<$limit &&
            // Still alive?
            !connection_aborted() &&
            // Restart session
            !headers_sent() &&
            (session_status()==PHP_SESSION_ACTIVE || session_start()) &&
            // CAUTION: Callback will kill host if it never becomes truthy!
            !$out=$this->call($func,$args)) {
            if (!$this->hive['CLI'])
                session_commit();
            // Hush down
            sleep(1);
        }
        ob_flush();
        flush();
        return $out;
    }


    /**
     * Grab the real route handler behind the string expression
     * Always returns either [$className, $method] or [$functionName]
     * @param string $func
     * @param array $args
     * @return array
     */
    function grab($func) {
        if(!is_string($func)) user_error('Grab function error', E_USER_ERROR);
        // "Class->method" или "Class::method"
        if (preg_match('/^(.+)\h*(->|::)\h*(.+)$/s', $func, $parts)) {
            if (!class_exists($parts[1])) {
                user_error(sprintf(self::E_Class, $parts[1]), E_USER_ERROR);
            }
            return [$parts[1], $parts[3]];
        }

        // Обычная функция в виде строки
        return [$func];
    }

    /**
    *   Execute callback/hooks (supports 'class->method' format)
    *   @return mixed|FALSE
    *   @param $func callback|array|string
    *   @param $args string
    *   @param $hooks string
    **/
    function call($func,$args=NULL) {
        if (!is_array($args))
            $args=[$args];
        // Grab the real handler behind the string representation
        if (is_string($func))
            $func=$this->grab($func);

        if (!is_array($func)) {
            $out = NULL;
            if(is_callable($func))
                $out = call_user_func_array($func, $args ?: []);
            return ($out === FALSE) ? FALSE : $out;
        }

        $count = count($func);
        if ($count == 1) {
            if (!is_callable($func[0])) {
                user_error(sprintf(self::E_Method, $func[0]), E_USER_ERROR);
            }
            $out = call_user_func_array($func[0], $args ?: []);
            return ($out === FALSE) ? FALSE : $out;
        } elseif ($count !== 2) {
            user_error('Invalid handler array: expected [$className, $method].', E_USER_ERROR);
        }

        if (is_string($func[0])) {
            $func[0] = $this->resolveFromContainer($func[0]);
        }

        $obj = is_object($func[0]);

        // main call
        $out = call_user_func_array($func, $args ?: []);
        if ($out === FALSE) return FALSE;

        return $out;
    }

    /**
     * Резолвим класс через контейнер.
     * Требует $this->hive['CONTAINER'] (PSR-11 или callable-резолвер).
     * @param string $class
     * @param array $args
     * @return object
     */
    function resolveFromContainer(string $class) {
        if (!isset($this->hive['CONTAINER'])) {
            user_error(sprintf(self::E_Container, $class), E_USER_ERROR);
        }
        $container = $this->hive['CONTAINER'];

        // PSR-11: has()/get()
        if (is_object($container) && (is_callable([$container,'get']) || is_callable([$container,'has']))) {
            if (is_callable([$container,'has'])) {
                if (method_exists($container, 'has') && !$container->has($class)) {
                    user_error(sprintf(self::E_Class, $class), E_USER_ERROR);
                }
            }
            return $container->get($class);
        }

        // Callable-резолвер: fn(string $class): object
        if (is_callable($container)) {
            $instance = call_user_func($container, $class);
            if (!is_object($instance)) {
                user_error(sprintf(self::E_Class, $class), E_USER_ERROR);
            }
            return $instance;
        }

        // Статический локатор вида Container::instance()->get()
        if (is_string($container) && class_exists($container) && method_exists($container, 'instance')) {
            $loc = call_user_func($container.'::instance');
            if (!is_object($loc) || !method_exists($loc, 'get')) {
                user_error(sprintf(self::E_Container, $class), E_USER_ERROR);
            }
            return $loc->get($class);
        }

        user_error(sprintf(self::E_Container, $class), E_USER_ERROR);
    }

    /**
    *   Execute specified callbacks in succession; Apply same arguments
    *   to all callbacks
    *   @return array
    *   @param $funcs array|string
    *   @param $args mixed
    **/
    function chain($funcs,$args=NULL) {
        $out=[];
        foreach (is_array($funcs)?$funcs:$this->split($funcs) as $func)
            $out[]=$this->call($func,$args);
        return $out;
    }

    /**
    *   Execute specified callbacks in succession; Relay result of
    *   previous callback as argument to the next callback
    *   @return array
    *   @param $funcs array|string
    *   @param $args mixed
    **/
    function relay($funcs,$args=NULL) {
        foreach (is_array($funcs)?$funcs:$this->split($funcs) as $func)
            $args=[$this->call($func,$args)];
        return array_shift($args);
    }

    /**
    *   Create mutex, invoke callback then drop ownership when done
    *   @return mixed
    *   @param $id string
    *   @param $func callback
    *   @param $args mixed
    **/
    function mutex($id,$func,$args=NULL) {
        if (!is_dir($tmp=$this->hive['TEMP']))
            mkdir($tmp,self::MODE,TRUE);
        // Use filesystem lock
        if (is_file($lock=$tmp.
            $this->hive['SEED'].'.'.$this->hash($id).'.lock') &&
            filemtime($lock)+ini_get('max_execution_time')<microtime(TRUE))
            // Stale lock
            @unlink($lock);
        while (!($handle=@fopen($lock,'x')) && !connection_aborted())
            usleep(mt_rand(0,100));
        $this->locks[$id]=$lock;
        $out=$this->call($func,$args);
        fclose($handle);
        @unlink($lock);
        unset($this->locks[$id]);
        return $out;
    }

    /**
    *   Read file (with option to apply Unix LF as standard line ending)
    *   @return string
    *   @param $file string
    *   @param $lf bool
    **/
    function read($file,$lf=FALSE) {
        $out=@file_get_contents($file);
        return $lf?preg_replace('/\r\n|\r/',"\n",$out):$out;
    }

    /**
    *   Exclusive file write
    *   @return int|FALSE
    *   @param $file string
    *   @param $data mixed
    *   @param $append bool
    **/
    function write($file,$data,$append=FALSE) {
        return file_put_contents($file,$data,$this->hive['LOCK']|($append?FILE_APPEND:0));
    }

    /**
    *   Apply syntax highlighting
    *   @return string
    *   @param $text string
    **/
    function highlight($text) {
        $out='';
        $pre=FALSE;
        $text=trim($text);
        if ($text && !preg_match('/^<\?php/',$text)) {
            $text='<?php '.$text;
            $pre=TRUE;
        }
        foreach (token_get_all($text) as $token)
            if ($pre)
                $pre=FALSE;
            else
                $out.='<span'.
                    (is_array($token)?
                        (' class="'.
                            substr(strtolower(token_name($token[0])),2).'">'.
                            $this->encode($token[1]).''):
                        ('>'.$this->encode($token))).
                    '</span>';
        return $out?('<code>'.$out.'</code>'):$text;
    }

    /**
    *   Return HTTP user agent
    *   @return string
    **/
    function agent() {
        $agent =
            $this->call([Request::class,'getHeader'], ['X-Operamini-Phone-UA']) ?:
            $this->call([Request::class,'getHeader'], ['X-Skyfire-Phone'])     ?:
            $this->call([Request::class,'getHeader'], ['User-Agent'])          ?:
            ($this->hive['AGENT'] ?? null);
        return $agent;
    }

    /**
    *   Return TRUE if XMLHttpRequest detected
    *   @return bool
    **/
    function ajax() {
        return (bool)$this->call([Request::class,'isAjax']);
    }

    /**
    *   Sniff IP address
    *   @return string
    **/
    function ip() {
        $xf  = (string)$this->call([Request::class,'getHeader'], ['X-Forwarded-For']);
        $cip = (string)$this->call([Request::class,'getHeader'], ['Client-IP']);
        if ($cip && filter_var($cip, FILTER_VALIDATE_IP)) return $cip;
        if ($xf && preg_match('/^\s*([^,]+)/', $xf, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) return $m[1];
        return $this->call([Request::class,'clientIp']);
    }

    function jar(array $override = null): array
    {
        /** @var \App\Base\CookieService $cookies */
        $cookies = $this->get('CookieService');
        if ($override !== null) {
            $cookies->configure($override);
            $this->set('JAR', array_replace($this->get('JAR') ?? [], $override));
        }
        // вернуть актуальные параметры
        return array_replace([
            'expires'=>0,'path'=>'/','domain'=>'',
            'secure'=>false,'httponly'=>true,'samesite'=>'Lax'
        ], $this->get('JAR') ?? []);
    }


    /**
    *   Return path (and query parameters) relative to the base directory
    *   @return string
    *   @param $url string
    **/
    function rel($url) {
        return preg_replace('/^(?:https?:\/\/)?'.
            preg_quote($this->hive['BASE'],'/').'(\/.*|$)/','\1',$url);
    }

    /**
    *   Execute framework/application shutdown sequence
    *   @param $cwd string
    **/
    function unload($cwd) {
        chdir($cwd);
        if (!($error=error_get_last()) &&
            session_status()==PHP_SESSION_ACTIVE)
            session_commit();
        foreach ($this->locks as $lock)
            @unlink($lock);
        $handler=$this->hive['UNLOAD'];
        if ((!$handler || $this->call($handler,$this)===FALSE) &&
            $error && in_array($error['type'],
            [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR]))
            // Fatal error detected
            $this->error(500,
                sprintf(self::E_Fatal,$error['message']),[$error]);
    }

    /**
    *   Call function identified by hive key
    *   @return mixed
    *   @param $key string
    *   @param $args array
    **/
    function __call($key,array $args) {
        if ($this->exists($key,$val))
            return call_user_func_array($val,$args);
        user_error(sprintf(self::E_Method,$key),E_USER_ERROR);
    }

    //! Prohibit cloning
    private function __clone() {
    }

    //! Bootstrap
    function bootstrap() {
        // Managed directives
        ini_set('default_charset',$charset='UTF-8');
        if (extension_loaded('mbstring'))
            mb_internal_encoding($charset);
        ini_set('display_errors',0);
        // Deprecated directives
        @ini_set('magic_quotes_gpc',0);
        @ini_set('register_globals',0);
        // Intercept errors/exceptions; PHP5.3-compatible
        if (PHP_VERSION_ID >= 80400) {
            $check = error_reporting((E_ALL) & ~(E_NOTICE | E_USER_NOTICE));
        } else {
            $check = error_reporting((E_ALL | E_STRICT) & ~(E_NOTICE | E_USER_NOTICE));
        }
        set_exception_handler(
            function($obj) {
                /** @var Exception $obj */
                $this->hive['EXCEPTION']=$obj;
                $this->error(500,
                    $obj->getMessage().' '.
                    '['.$obj->getFile().':'.$obj->getLine().']',
                    $obj->getTrace());
            }
        );
        set_error_handler(
            function($level,$text,$file,$line) {
                if ($level & error_reporting()) {
                    $trace=$this->trace(null, false);
                    array_unshift($trace,['file'=>$file,'line'=>$line]);
                    $this->error(500,$text,$trace,$level);
                }
            }
        );
        
        // Снимок окружения и нормализация
        $env = Environment::init(function (Environment $env) {
            // Тут же можно задать доверенные прокси/хосты:
            /*$env->setTrustedProxies(['127.0.0.1','10.0.0.0/8'])
                ->setTrustedHosts(['^oasis\\.local$', '^md\\.local$'])
                ->honorForwarded(true, true);*/
        }, readBody: true);

        $cli = PHP_SAPI=='cli';
        $base='/';
        if (!$cli)
            $base=$env->base();

        // Default configuration
        $this->hive=[
            'ALIAS'=>'',
            'DI_AUTOWIRING'=>TRUE,
            'BASE'=>$base,
            'BITMASK'=>ENT_COMPAT,
            'CACHE'=>FALSE,
            'CASELESS'=>TRUE,
            'CLI'=>$cli,
            'CORS'=>[],
            'DEBUG'=>0,
            'DIACRITICS'=>[],
            'DNSBL'=>'',
            'EMOJI'=>[],
            'ENCODING'=>$charset,
            'ERROR'=>NULL,
            'ESCAPE'=>TRUE,
            'EXCEPTION'=>NULL,
            'EXEMPT'=>NULL,
            'FORMATS'=>[],
            'HALT'=>TRUE,
            'HIGHLIGHT'=>FALSE,
            'LOCK'=>LOCK_EX,
            'LOGGABLE'=>'*',
            'LOGS'=>'./',
            'MB'=>extension_loaded('mbstring'),
            'ONERROR'=>NULL,
            'ONREROUTE'=>NULL,
            'PARAMS'=>[],
            'REROUTE_TRAILING_SLASH'=>TRUE,
            'PATTERN'=>NULL,
            'PLUGINS'=>$this->fixslashes(__DIR__).'/',
            'PREFIX'=>NULL,
            'PREMAP'=>'',
            'QUIET'=>FALSE,
            'RAW'=>FALSE,
            'RESPONSE'=>'',
            'SEED'=>$this->hash($_SERVER['SERVER_NAME'].$base),
            'SERIALIZER'=>extension_loaded($ext='igbinary')?$ext:'php',
            'TEMP'=>'tmp/',
            'TIME'=>&$_SERVER['REQUEST_TIME_FLOAT'],
            'TZ'=>@date_default_timezone_get(),
            'UI'=>'./',
            'UNLOAD'=>NULL,
            'UPLOADS'=>'./',
            'XFRAME'=>'SAMEORIGIN'
        ];

        

        // Настройки cookie для сессии (на основе Environment)
        $jar = Environment::instance()->sessionCookieParams();
        if (!headers_sent() && session_status() !== PHP_SESSION_ACTIVE) {
            session_cache_limiter('');
            if (version_compare(PHP_VERSION, '7.3.0') >= 0) {
                session_set_cookie_params($jar);
            }
        }
        $this->hive['JAR']=$jar;
        
        $this->hive['CORS']+=[
            'headers'=>'',
            'origin'=>FALSE,
            'credentials'=>FALSE,
            'expose'=>FALSE,
            'ttl'=>0
        ];
        if (ini_get('auto_globals_jit')) {
            // Override setting
            $GLOBALS['_ENV']=$_ENV;
            $GLOBALS['_REQUEST']=$_REQUEST;
        }
        
        if ($check && $error=error_get_last())
            // Error detected
            $this->error(500,
                sprintf(self::E_Fatal,$error['message']),[$error]);
        date_default_timezone_set($this->hive['TZ']);
        // Register shutdown handler
        register_shutdown_function([$this,'unload'],getcwd());
    }
}
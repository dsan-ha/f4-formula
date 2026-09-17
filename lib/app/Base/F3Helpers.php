<?php

declare(strict_types=1);

namespace App\Base;

use App\Http\Environment;
use App\Http\ErrorHandler;
use App\Http\Response;

/**
 * F4 facade/provider helpers. Business logic belongs to the underlying services.
 */
trait F3Helpers
{
    private const Cache_folder = 'base';

    private function cacheService(): \App\Utils\Cache
    {
        return $this->cacheServiceInternal();
    }

    function &ref($key, $add = true, &$var = null) {
        return $this->storeInternal()->ref((string)$key, (bool)$add, $var);
    }

    function exists($key, &$val = null) {
        if ($this->storeInternal()->exists((string)$key, $val)) return true;
        return $this->hasCacheServiceInternal()
            && ($this->cacheService()->exists($this->hash($key).'.var', self::Cache_folder, $val) ?: false);
    }

    function set($key, $val, $ttl = 0) {
        if (preg_match('/^COOKIE\b/', (string)$key)) {
            $parts = preg_split('/\./', (string)$key, 2);
            $name = $parts[1] ?? null;
            if ($name) $this->cookieServiceInternal()->set($name, $val ?: '', (int)$ttl);
            return $val;
        }
        if ($key === 'ENCODING') {
            ini_set('default_charset', (string)$val);
            if (extension_loaded('mbstring')) mb_internal_encoding((string)$val);
        } elseif ($key === 'TZ') {
            date_default_timezone_set((string)$val);
        }
        $out = $this->storeInternal()->set((string)$key, $val);
        if ($ttl && $this->hasCacheServiceInternal()) {
            $this->cacheService()->set($this->hash($key).'.var', self::Cache_folder, $val, (int)$ttl);
        }
        return $out;
    }

    function get($key, $args = null) {
        $val = $this->storeInternal()->get((string)$key);
        if (is_string($val) && $args !== null) {
            return call_user_func_array([$this,'format'], array_merge([$val], is_array($args) ? $args : [$args]));
        }
        if ($val === null && $this->hasCacheServiceInternal()
            && $this->cacheService()->exists($this->hash($key).'.var', self::Cache_folder, $data)) {
            return $data;
        }
        return $val;
    }

    function clear($key) {
        $cache = $this->hasCacheServiceInternal() ? $this->cacheService() : null;
        if ($key === 'CACHE' && $cache) $cache->clearFolder(self::Cache_folder);
        elseif (preg_match('/^COOKIE\b/', (string)$key)) {
            $parts = preg_split('/\./', (string)$key, 2);
            $name = $parts[1] ?? null;
            if ($name) $this->cookieServiceInternal()->clear($name);
        }
        $this->storeInternal()->clear((string)$key);
        if ($cache && $cache->exists($hash=$this->hash($key).'.var', self::Cache_folder)) $cache->clear($hash, self::Cache_folder);
    }

    function mset(array $vars, $prefix = '', $ttl = 0) {
        foreach ($vars as $key => $val) $this->set($prefix.$key, $val, $ttl);
    }

    function hive() { return $this->storeInternal()->all(); }

    function extend($key, $src, $keep = false) {
        $ref = &$this->ref($key);
        if (!$ref) $ref = [];
        $source = is_string($src) ? $this->get($src, []) : $src;
        $out = array_replace_recursive((array)$source, (array)$ref);
        if ($keep) $ref = $out;
        return $out;
    }


    function cache_exists(string $key, &$value = null)
        {
            return $this->cacheService()->exists($key, self::Cache_folder, $value);
        }

    function cache_set(string $key, $value, int $ttl = 0)
        {
            $this->cacheService()->set($key, self::Cache_folder, $value, $ttl);
        }

    function cache_get(string $key, $def = '')
        {
            return $this->cacheService()->get($key, self::Cache_folder, $def);
        }

    function cache_clear(string $key)
        {
            return $this->cacheService()->clear($key, self::Cache_folder);
        }

    function status($code, $res = null, $send = false) {
        $reason = Response::reasonPhrase((int)$code);
        if ($send && !$this->environmentInternal()->isCli() && !headers_sent()) {
            http_response_code((int)$code);
        }
        return $reason;
    }


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
                // Got time left?
                time()-$time+1<$limit &&
                // Still alive?
                !connection_aborted() &&
                // Restart session
                !headers_sent() &&
                (session_status()==PHP_SESSION_ACTIVE || session_start()) &&
                // CAUTION: Callback will kill host if it never becomes truthy!
                !$out=$this->call($func,$args)) {
                if (!$this->get('CLI'))
                    session_commit();
                // Hush down
                sleep(1);
            }
            ob_flush();
            flush();
            return $out;
        }

    function agent() {
        return $this->requestInternal()->getHeader('X-Operamini-Phone-UA')
            ?: $this->requestInternal()->getHeader('X-Skyfire-Phone')
            ?: $this->requestInternal()->getHeader('User-Agent')
            ?: $this->get('AGENT');
    }

    function ajax() {
        return $this->requestInternal()->isAjax();
    }

    function ip() {
        return $this->requestInternal()->clientIp();
    }

    function jar(array $override = []): array
        {
            /** @var \App\Base\CookieService $cookies */
            $cookies = $this->cookieServiceInternal();
            if (!empty($override)) {
                $cookies->configure($override);
                $this->set('JAR', array_replace($this->get('JAR') ?? [], $override));
            }
            // вернуть актуальные параметры
            return array_replace([
                'expires'=>0,'path'=>'/','domain'=>'',
                'secure'=>false,'httponly'=>true,'samesite'=>'Lax'
            ], $this->get('JAR') ?? []);
        }

    function rel($url) {
        $base = $this->environmentInternal()->base();
        return preg_replace('/^(?:https?:\/\/)?'.preg_quote($base,'/').'(\/.*|$)/','\1',$url);
    }

    function unload($cwd) {
            chdir($cwd);
            if (session_status()==PHP_SESSION_ACTIVE)
                session_commit();
            foreach ($this->locks as $lock)
                @unlink($lock);
            $handler=$this->get('UNLOAD');
            if ($handler)
                $this->call($handler,$this);
        }

    function __call($key,array $args) {
            if ($this->exists($key,$val))
                return call_user_func_array($val,$args);
            user_error(sprintf(self::E_Method,$key),E_USER_ERROR);
        }

    private function __clone() {
        }

    function bootstrap() {
            // Managed directives
            ini_set('default_charset',$charset='UTF-8');
            if (extension_loaded('mbstring'))
                mb_internal_encoding($charset);
            ini_set('display_errors',0);
            // Deprecated directives
            @ini_set('magic_quotes_gpc',0);
            @ini_set('register_globals',0);
            error_reporting((E_ALL) & ~(E_NOTICE | E_USER_NOTICE));
            
            // Снимок окружения и нормализация
            $env = Environment::init(function (Environment $env) {
                // Тут же можно задать доверенные прокси/хосты:
                /*$env->setTrustedProxies(['127.0.0.1','10.0.0.0/8'])
                    ->setTrustedHosts(['^oasis\\.local$', '^md\\.local$'])
                    ->honorForwarded(true, true);*/
            }, readBody: true);
    
            ErrorHandler::bootstrap($env)->register();
            $this->setEnvironmentInternal($env);
    
            $cli = PHP_SAPI=='cli';
            $base='/';
            if (!$cli)
                $base=$env->base();
    
            // Default configuration
            $defaults = [
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
                'ESCAPE'=>TRUE,
                'EXEMPT'=>NULL,
                'FORMATS'=>[],
                'JSON_SECURE'=>TRUE, 
                'HIGHLIGHT'=>FALSE,
                'LOCK'=>LOCK_EX,
                'LOGGABLE'=>'*',
                'LOGS'=>'./',
                'MB'=>extension_loaded('mbstring'),
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
            foreach ($defaults as $key => $value) {
                $this->storeInternal()->set((string)$key, $value);
            }
    
            
    
            // Настройки cookie для сессии (на основе Environment)
            $jar = $env->sessionCookieParams();
            if (!headers_sent() && session_status() !== PHP_SESSION_ACTIVE) {
                session_cache_limiter('');
                if (version_compare(PHP_VERSION, '7.3.0') >= 0) {
                    session_set_cookie_params($jar);
                }
            }
            $this->set('JAR', $jar);
            
            $cors = (array)$this->get('CORS', []);
            $cors += [
                'headers'=>'',
                'origin'=>FALSE,
                'credentials'=>FALSE,
                'expose'=>FALSE,
                'ttl'=>0
            ];
            $this->set('CORS', $cors);
            if (ini_get('auto_globals_jit')) {
                // Override setting
                $GLOBALS['_ENV']=$_ENV;
                $GLOBALS['_REQUEST']=$_REQUEST;
            }
            date_default_timezone_set($this->get('TZ'));
            // Register shutdown handler
            register_shutdown_function([$this,'unload'],getcwd());
        }
}

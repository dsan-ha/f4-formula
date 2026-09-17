<?php

declare(strict_types=1);

namespace App\Base;

/**
 * General-purpose framework tools.
 * Storage and framework/provider glue live outside this trait.
 */
trait F3Tools
{
    private const MODE = 0755;
    private const E_Class = 'Invalid class %s';
    private const E_Method = 'Invalid method %s';

    private array $locks = [];

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

    function extract($arr,$prefix) {
            $out=[];
            foreach (preg_grep('/^'.preg_quote($prefix,'/').'/',array_keys($arr))
                as $key)
                $out[substr($key,strlen($prefix))]=$arr[$key];
            return $out;
        }

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

    function visible($obj,$key) {
            if (property_exists($obj,$key)) {
                $ref=new \ReflectionProperty(get_class($obj),$key);
                $out=$ref->isPublic();
                unset($ref);
                return $out;
            }
            return FALSE;
        }

    function fixslashes($str) {
            return $str?strtr($str,'\\','/'):$str;
        }

    function split($str,$noempty=TRUE) {
            return array_map('trim',
                preg_split('/[,;|]/',$str?:'',0,$noempty?PREG_SPLIT_NO_EMPTY:0));
        }

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

    function csv(array $args) {
            return implode(',',array_map('stripcslashes',
                array_map([$this,'stringify'],$args)));
        }

    function format(string $tpl, ...$args) {
            return vsprintf($tpl, $args);
        }

    function export($expr) {
            return var_export($expr,TRUE);
        }

    function constants($class,$prefix='') {
            $ref=new \ReflectionClass($class);
            return $this->extract($ref->getconstants(),$prefix);
        }

    function hash($str) {
            return str_pad(base_convert(
                substr(sha1($str?:''),-16),16,36),11,'0',STR_PAD_LEFT);
        }

    function encode($str) {
            return @htmlspecialchars($str,$this->get('BITMASK'),
                $this->get('ENCODING'))?:$this->scrub($str);
        }

    function decode($str) {
            return htmlspecialchars_decode($str,$this->get('BITMASK'));
        }

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

    function scrub(&$var,$tags=NULL) {
            return $var=$this->clean($var,$tags);
        }

    function serialize($arg) {
            switch (strtolower($this->get('SERIALIZER'))) {
                case 'igbinary':
                    return igbinary_serialize($arg);
                default:
                    return serialize($arg);
            }
        }

    function unserialize($arg) {
            switch (strtolower($this->get('SERIALIZER'))) {
                case 'igbinary':
                    return igbinary_unserialize($arg);
                default:
                    return unserialize($arg);
            }
        }

    function trace(?array $trace=NULL,$format=TRUE) {
            if (!$trace) {
                $trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                $frame=$trace[0];
                if (isset($frame['file']) && $frame['file']==__FILE__)
                    array_shift($trace);
            }
            $debug=$this->get('DEBUG');
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
                $func[0] = $this->resolveHandlerClassInternal($func[0]);
            }
    
            $obj = is_object($func[0]);
    
            // main call
            $out = call_user_func_array($func, $args ?: []);
            if ($out === FALSE) return FALSE;
    
            return $out;
        }

    function chain($funcs,$args=NULL) {
            $out=[];
            foreach (is_array($funcs)?$funcs:$this->split($funcs) as $func)
                $out[]=$this->call($func,$args);
            return $out;
        }

    function relay($funcs,$args=NULL) {
            foreach (is_array($funcs)?$funcs:$this->split($funcs) as $func)
                $args=[$this->call($func,$args)];
            return array_shift($args);
        }

    function mutex($id,$func,$args=NULL) {
            if (!is_dir($tmp=$this->get('TEMP')))
                mkdir($tmp,self::MODE,TRUE);
            // Use filesystem lock
            if (is_file($lock=$tmp.
                $this->get('SEED').'.'.$this->hash($id).'.lock') &&
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

    function read($file,$lf=FALSE) {
            $out=@file_get_contents($file);
            return $lf?preg_replace('/\r\n|\r/',"\n",$out):$out;
        }

    function write($file,$data,$append=FALSE) {
            return file_put_contents($file,$data,$this->get('LOCK')|($append?FILE_APPEND:0));
        }

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
}

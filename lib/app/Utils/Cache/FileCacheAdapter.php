<?php

namespace App\Utils\Cache;

use App\F4;

class FileCacheAdapter implements CacheInterface
{
    protected string $baseDir;
    protected string $seed;

    public function __construct(string $baseDir, string $seed = '')
    {
        if($baseDir){
            $this->baseDir = rtrim($baseDir,'/ ') . '/';
        } else {
           throw new \Exception("Cache base directory is required"); 
        }
        $this->seed = $seed?:'';
    }

    /* ==================== Paths ==================== */

    protected function safeFolder(string $folder): string
    {
        return preg_replace('/[^\/a-zA-Z0-9_\-]/', '', str_replace('\\', '/', trim($folder, '/')));
    }

    protected function safeKey(string $key): string
    {
        $k = str_replace('\\', '/', $key);
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $k);
    }

    protected function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    protected function getPath(string $key, string $folder): string
    {
        $dir = $this->baseDir;
        $sf  = $this->safeFolder($folder);
        if ($sf) { $dir .= $sf . '/'; }
        $this->ensureDir($dir);
        $key = $this->safeKey($key);
        $seed = substr(sha1($this->seed.$key),-10);
        return $dir . $seed.'.'. substr(sha1($key), 0, 16) . '.cache';
    }

    public function set(string $key, string $folder, $value, int $ttl = 0, array $meta = []) : bool
    {
        $path     = $this->getPath($key, $folder);


        // 1) первая строка JSON, со 2-й строки — value (как есть или json, если не строка)
        $base_meta = [
            'time'     => microtime(true),
            'ttl'      => (int)$ttl,
            'enc'      => 'raw',
        ];

        $body = $this->encodeValue($value, $base_meta['enc']);
        $meta = array_merge($meta,$base_meta);
        $header = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


        if ($header === false) {
            throw new \RuntimeException('Cache header value is not JSON-serializable');
        }
        $payload = $header . "\n" . $body;

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(3));
        $ok  = (file_put_contents($tmp, $payload, LOCK_EX) !== false) && @rename($tmp, $path);
        if (!$ok) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    public function exists(string $key, string $folder, &$value = null) : bool
    {
        $path = $this->getPath($key, $folder);
        if (!is_file($path)) return false;

        $fp = @fopen($path, 'rb');
        if (!$fp) return false;

        // читаем первую строку (заголовок)
        $header = fgets($fp, 65536);
        if ($header === false) { fclose($fp); return false; }

        $meta = json_decode($header, true);
        if (!is_array($meta) || !isset($meta['time'], $meta['ttl'])) { fclose($fp); return false; }

        // TTL
        $ttl = (int)$meta['ttl'];
        $tm  = (float)$meta['time'];
        unset($meta['ttl']);
        unset($meta['time']);
        if ($ttl > 0 && ($tm + $ttl) <= microtime(true)) {
            fclose($fp);
            // ttl истёк — удаляем и meta
            $this->clear($key, $folder);
            return false;
        }

        // остальная часть файла — это value
        $body = stream_get_contents($fp);
        fclose($fp);

        $enc = isset($meta['enc']) ? (string)$meta['enc'] : 'raw';
        unset($meta['enc']);
        try {
            $val = $this->decodeValue($body ?: '', $enc);
        } catch (\Throwable $e) {
            // битый кэш — удаляем и считаем, что его нет
            $this->clear($key, $folder);
            return false;
        }


        // по новому контракту: возвращаем [$value, $meta]
        $value = [$val, $meta];
        return true;
    }

    public function get(string $key, string $folder, $def = null)
    {
        return $this->exists($key, $folder, $value) ? $value : [$def,[]];
    }

    public function clear(string $key, string $folder): bool
    {
        $path = $this->getPath($key, $folder);
        return @unlink($path);
    }

    public function clearFolder(string $folder): void
    {
        $sf = $this->safeFolder($folder);
        $dir = $this->baseDir . ($sf ? $sf.'/' : '');
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @mkdir($dir, 0777, true);
    }

    private function encodeValue($value, &$enc = 'raw'): string {
        if (is_string($value)) {
            $enc = 'raw';
            return $value;                      // пишем как есть
        }
        if (is_scalar($value)) {
            $enc = 'raw';
            return (string)$value;              // int/float/bool -> строка
        }
        // array|object -> json
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            // Тут можно сделать fallback на serialize(), но это уже другой класс рисков.
            throw new \RuntimeException('Cache value is not JSON-serializable');
        }
        $enc = 'json';
        return $json;
    }

    private function decodeValue(string $body, string $enc) {
        if ($enc === 'json') {
            // Возвращаем ассоц.массив по умолчанию; если хочешь объекты — убери true
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return $decoded;
        }
        // raw
        return $body;
    }
}

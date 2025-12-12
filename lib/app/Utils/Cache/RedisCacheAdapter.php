<?php

namespace App\Utils\Cache;

use App\F4;

class RedisCacheAdapter implements CacheInterface
{
    protected \Redis $redis;
    protected string $seed;
    private const META_PREFIX = 'm:';

    public function __construct(string $seed = '')
    {
        $f4 = F4::instance();
        $host = $f4->g('cache.redis_host','127.0.0.1');
        $port = $f4->g('cache.redis_port',6379);
        $db = $f4->g('cache.redis_db',0);
        $this->redis = new \Redis();
        $this->redis->connect($host, $port);
        $this->redis->select($db);
        $this->seed = $seed?:'';
    }

    protected function makeKey(string $key, string $folder): string
    {
        $safeFolder = preg_replace('/[^a-zA-Z0-9_\-]/', '', str_replace("\\/",'_',$folder));
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $key);
        return $safeFolder . '.' . sha1($this->seed.$safeKey);
    }

    private function pack($value, int $ttl, array $meta): array {
        $store = ['value'=>$value, 'time'=>microtime(true), 'ttl'=>$ttl];
        foreach ($meta as $k=>$v) $store[self::META_PREFIX.$k] = $v;
        return $store;
    }

    private function unpack($data, &$val, &$meta): bool {
        if (!is_array($data) || !isset($data['value'],$data['time'],$data['ttl'])) return false;
        $now = microtime(true);
        if ($data['ttl'] > 0 && ($data['time'] + $data['ttl']) <= $now) return false;
        $val = $data['value'];
        $meta = [];
        foreach ($data as $k=>$v) {
            if (strncmp($k, self::META_PREFIX, 2) === 0) $meta[substr($k,2)] = $v;
        }
        return true;
    }

    public function set(string $key, string $folder, $value, int $ttl = 0, array $meta = []): bool
    {
        $k = $this->makeKey($key, $folder);
        $data = serialize($this->pack($value, $ttl, $meta));
        return $ttl > 0
            ? $this->redis->setex($k, $ttl, $data)
            : $this->redis->set($k, $data);
    }

    public function exists(string $key, string $folder, &$value = null): bool
    {
        $k = $this->makeKey($key, $folder);
        $raw = $this->redis->get($k);
        $data = @unserialize($raw);

        if (!$this->unpack($data, $val, $meta)) {
            $this->clear($key, $folder);
            return false;
        }
        $value = [$val, $meta];
        return true;
    }

    public function get(string $key, string $folder, $def = null)
    {
        return $this->exists($key, $folder, $value) ? $value : [$def, []];
    }

    public function clear(string $key, string $folder): bool
    {
        return (bool)$this->redis->del($this->makeKey($key, $folder));
    }

    public function clearFolder(string $folder): void
    {
        $pattern = $this->makeKey('*', $folder);
        $it = null;
        while ($keys = $this->redis->scan($it, $pattern)) {
            foreach ($keys as $key) {
                $this->redis->del($key);
            }
        }
    }
}

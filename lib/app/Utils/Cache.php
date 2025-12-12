<?php

namespace App\Utils;

use App\Utils\Cache\CacheInterface;
use App\F4;

class Cache implements CacheInterface
{
    protected CacheInterface $adapter;

    public function __construct(CacheInterface $adapter)
    {
        if(empty($adapter)) throw new \Exception("Cache adapter not found");
        $this->adapter = $adapter;
    }

    public function add(string $key, $value, int $ttl = 30, array $meta = []): bool {
        $folder = '';
        if ($this->exists($key, $folder, $tmp)) return false;
        $this->set($key, $folder, $value, $ttl);
        return true;
    }

    public function set(string $key, string $folder, $value, int $ttl = 0, $meta = []): bool
    {
        return $this->adapter->set($key, $folder, $value, $ttl, $meta);
    }

    public function get(string $key, string $folder, $def = null)
    {
        $arValue = $this->adapter->get($key, $folder, $def);
        return $arValue[0];
    }

    public function exists(string $key, string $folder, &$value = null): bool
    {
        $arValue = [];
        $ok = $this->adapter->exists($key, $folder, $arValue);
        if(is_array($arValue) && count($arValue) == 2) $value = $arValue[0];
        return $ok;
    }

    public function clear(string $key, string $folder): bool
    {
        return $this->adapter->clear($key, $folder);
    }

    public function clearFolder(string $folder): void
    {
        $this->adapter->clearFolder($folder);
    }

    public function adapterName(): string
    {
        return $this->adapter::class;
    }
}

<?php

namespace App\Utils\Cache;

interface CacheInterface
{
    public function set(string $key, string $folder, $value, int $ttl = 0, array $meta = []): bool;
    public function get(string $key, string $folder, $def = null);
    public function exists(string $key, string $folder, &$value = null): bool;
    public function clear(string $key, string $folder): bool;
    public function clearFolder(string $folder): void;
}

<?php
namespace App\Utils\Cache;

interface CacheInterface
{
    /**
     * Set cache value
     * @param string $key
     * @param string $folder
     * @param mixed $value
     * @param int $ttl
     * @param array $meta
     * @return bool
     */
    public function set(string $key, string $folder, $value, int $ttl = 0, array $meta = []): bool;
    
    /**
     * Get cache value
     * @param string $key
     * @param string $folder
     * @param mixed $def
     * @return mixed
     */
    public function get(string $key, string $folder, $def = null);
    
    /**
     * Check if cache exists
     * @param string $key
     * @param string $folder
     * @param mixed $value
     * @return bool
     */
    public function exists(string $key, string $folder, &$value = null): bool;
    
    /**
     * Clear cache by key
     * @param string $key
     * @param string $folder
     * @return bool
     */
    public function clear(string $key, string $folder): bool;
    
    /**
     * Clear entire folder
     * @param string $folder
     * @return void
     */
    public function clearFolder(string $folder): void;
}
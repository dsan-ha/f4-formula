<?php
declare(strict_types=1);

namespace App\Utils;

final class FileCacheNonContainer
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->ensureDirectory($this->basePath);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->filePath($key);

        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $data = @unserialize($raw);
        if (!is_array($data)) {
            @unlink($file);
            return $default;
        }

        $expiresAt = $data['expires_at'] ?? 0;
        if ($expiresAt > 0 && $expiresAt < time()) {
            @unlink($file);
            return $default;
        }

        return $data['value'] ?? $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $file = $this->filePath($key);

        $payload = [
            'expires_at' => $ttl > 0 ? (time() + $ttl) : 0,
            'value' => $value,
        ];

        $tmp = $file . '.' . uniqid('tmp_', true);
        file_put_contents($tmp, serialize($payload), LOCK_EX);
        @rename($tmp, $file);
    }

    public function delete(string $key): void
    {
        $file = $this->filePath($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function clear(): void
    {
        $pattern = $this->basePath . '/*.cache.php';
        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function purgeExpired(): void
    {
        $pattern = $this->basePath . '/*.cache.php';

        foreach (glob($pattern) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                @unlink($file);
                continue;
            }

            $data = @unserialize($raw);
            if (!is_array($data)) {
                @unlink($file);
                continue;
            }

            $expiresAt = $data['expires_at'] ?? 0;
            if ($expiresAt > 0 && $expiresAt < time()) {
                @unlink($file);
            }
        }
    }

    private function filePath(string $key): string
    {
        return $this->basePath . '/' . sha1($key) . '.cache.php';
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}
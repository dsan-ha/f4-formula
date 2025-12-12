<?php
declare(strict_types=1);

namespace App\Base;

final class CookieService extends Magic
{
    /** Базовые дефолты для setcookie */
    private array $defaults = [
        'expires'  => 0,        // сеансовая
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',    // 'Lax' | 'Strict' | 'None'
    ];

    /** Переопределить дефолты опций */
    public function configure(array $opts): void
    {
        // принимаем как из Environment::sessionCookieParams(), так и твои jar()
        $map = $opts;
        if (isset($map['expire']) && !isset($map['expires'])) {
            $map['expires'] = (int)$map['expire'];
            unset($map['expire']);
        }
        $this->defaults = array_replace($this->defaults, $map);
    }

    public function exists($key): bool
    {
        return array_key_exists($key, $_COOKIE);
    }

    public function set(string $key, mixed $val): void
    {
        $val = (string) $val;
        $p = $this->defaults;
        // if ($ttl > 0) $p['expires'] = time() + $ttl;
        setcookie($key, $val, $p);
        $_COOKIE[$key] = $val;
    }

    public function get($key, $default = null): mixed
    {
        $val = $_COOKIE[$key] ?? $default;
        return $val; // ссылку на временную переменную в PHP отдавать ок — здесь достаточно сигнатуры совместимости с Magic
    }

    public function clear(string $key): void
    {
        $p = $this->defaults;
        $p['expires'] = 1; // в прошлом
        setcookie($key, '', $p);
        unset($_COOKIE[$key]);
    }

    /** Массовое чтение */
    public function all(): array
    {
        return $_COOKIE;
    }

    /** Удобный сахар: json-куки */
    public function setJson(string $key, mixed $data, int $ttl = 0, array $opts = [], int $jsonFlags = JSON_UNESCAPED_UNICODE): void
    {
        $this->set($key, json_encode($data, $jsonFlags), $ttl, $opts);
    }

    public function getJson(string $key, mixed $default = null): mixed
    {
        if (!$this->exists($key)) return $default;
        $raw = $_COOKIE[$key];
        $val = json_decode((string)$raw, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $val : $default;
    }
}

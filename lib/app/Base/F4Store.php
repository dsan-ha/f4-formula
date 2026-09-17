<?php

declare(strict_types=1);

namespace App\Base;

/**
 * Semantic state storage for F4.
 * No HTTP, DI, cache, cookie or runtime responsibilities belong here.
 */
final class F4Store
{
    private array $data = [];

    private function cut(string $key): array
    {
        return preg_split('/\[\h*[\'\"]?(.+?)[\'\"]?\h*\]|(->)|\./', $key, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: [];
    }

    public function &ref(string $key, bool $add = true, mixed &$source = null): mixed
    {
        $null = null;
        $parts = $this->cut($key);
        if (!$parts || !preg_match('/^\w+$/', (string)$parts[0])) {
            throw new \InvalidArgumentException('Invalid store key: ' . $key);
        }

        if ($source === null) {
            if ($add) {
                $source = &$this->data;
            } else {
                $source = $this->data;
            }
        }

        $prev = null;
        foreach ($parts as $part) {
            if ($part === '->' || $part === '.') {
                $prev = $part;
                continue;
            }

            if ($prev === '->') {
                if (!is_object($source)) {
                    if (!$add) { $source = &$null; break; }
                    $source = new \stdClass();
                }
                if ($add || property_exists($source, $part)) $source = &$source->$part;
                else { $source = &$null; break; }
            } else {
                if (is_object($source) && $prev === '.') {
                    if ($add || property_exists($source, $part)) $source = &$source->$part;
                    else { $source = &$null; break; }
                } else {
                    if (!is_array($source)) {
                        if (!$add) { $source = &$null; break; }
                        $source = [];
                    }
                    if ($add || array_key_exists($part, $source)) $source = &$source[$part];
                    else { $source = &$null; break; }
                }
            }
            $prev = null;
        }
        return $source;
    }

    public function exists(string $key, mixed &$value = null): bool
    {
        $value = $this->ref($key, false);
        return isset($value);
    }

    public function set(string $key, mixed $value): mixed
    {
        $ref = &$this->ref($key);
        $ref = $value;
        return $ref;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->ref($key, false);
        return $value === null ? $default : $value;
    }

    public function clear(string $key): void
    {
        $parts = $this->cut($key);
        if (!$parts) return;
        if (count($parts) === 1) {
            unset($this->data[$parts[0]]);
            return;
        }
        $last = array_pop($parts);
        while ($parts && ($parts[array_key_last($parts)] === '.' || $parts[array_key_last($parts)] === '->')) array_pop($parts);
        $parentKey = implode('.', array_filter($parts, fn($v) => $v !== '.' && $v !== '->'));
        if ($parentKey === '') return;
        $parent = &$this->ref($parentKey, false);
        if (is_array($parent)) unset($parent[$last]);
        elseif (is_object($parent) && property_exists($parent, $last)) unset($parent->$last);
    }

    public function all(): array { return $this->data; }
}

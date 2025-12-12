<?php
namespace App\Service\DB;

use App\Utils\Cache as BaseCache;

/**
 * Обёртка над базовым Cache с поддержкой тэгов и локов.
 * Прячет $folder, даёт простые API: get/set/has/add/clear, remember(), invalidateTag().
 */
final class Cache
{
    /** Папка/namespace для всего DM-кэша прячем здесь */
    private const FOLDER = 'dm';

    public function __construct(private BaseCache $base) {}

    /* ========= БАЗОВЫЕ ОПЕРАЦИИ ========= */

    public function has(string $key): bool {
        // exists($key, $folder, &$val) — сигнатура вашего базового кэша
        return $this->base->exists($key, self::FOLDER, $tmp);
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->base->exists($key, self::FOLDER, $val) ? $val : $default;
    }

    public function set(string $key, mixed $value, int $ttl = 60, array $tags = []): void {
        $this->base->set($key, self::FOLDER, $value, $ttl);
        if ($tags) $this->indexTags($key, $tags, $ttl);
    }

    public function add(string $key, mixed $value, int $ttl = 60): bool {
        // add: set if not exists
        if ($this->has($key)) return false;
        $this->base->set($key, self::FOLDER, $value, $ttl);
        return true;
    }

    public function delete(string $key): void {
        $this->base->clear($key, self::FOLDER);
        $this->removeKeyFromAllTags($key);
    }

    /* ========= ТЭГИ ========= */

    public function invalidateTag(string $tag): void {
        $tkey = $this->tagIndexKey($tag);
        $keys = $this->get($tkey, []);
        if (is_array($keys)) {
            foreach ($keys as $k) { $this->base->clear($k, self::FOLDER); }
        }
        $this->base->clear($tkey, self::FOLDER);
    }

    public function invalidateTags(array $tags): void {
        foreach ($tags as $t) $this->invalidateTag($t);
    }

    /* ========= REMEMBER + LOCK ========= */

    /**
     * Запоминает результат producer() c TTL и тэгами. Встроена защита от stampede.
     */
    public function remember(string $key, int $ttl, array $tags, callable $producer): mixed {
        if ($ttl <= 0) return $producer();
        if ($this->has($key)) return $this->get($key);

        $lock = $key.'.lock';
        if ($this->add($lock, 1, 10)) {
            try {
                $data = $producer();
                $this->set($key, $data, $ttl, $tags);
                return $data;
            } finally {
                $this->delete($lock);
            }
        }
        // подождём и попробуем взять из кэша
        usleep(200_000);
        return $this->get($key, $producer());
    }

    /* ========= ВНУТРЕННОСТИ ТЭГОВ ========= */

    private function tagIndexKey(string $tag): string { return "tag:{$tag}"; }

    private function indexTags(string $key, array $tags, int $ttl): void {
        $tags = array_values(array_unique(array_filter($tags)));
        foreach ($tags as $t) {
            $tkey = $this->tagIndexKey($t);
            $list = $this->get($tkey, []);
            if (!in_array($key, $list, true)) {
                $list[] = $key;
                // index сам кэшируем в том же namespace
                $this->base->set($tkey, self::FOLDER, $list, max($ttl, 60));
            }
        }
    }

    private function removeKeyFromAllTags(string $key): void {
        // Опционально: хранить «обратный индекс» key->tags. Для простоты можно пропустить.
        // Лёгкая версия: ничего не делаем — тэг очистит ключи при invalidateTag().
    }
}

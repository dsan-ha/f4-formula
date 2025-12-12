<?php

namespace App\View;

use App\Utils\Cache\CacheInterface;
use App\F4;

/**
 * Компонентный кэш
 * - Папка кэша: component/<group>/<name>
 * - Ключ: cmp:<group>:<name>:tpl:<template>:<hash(params)>
 * - Поддерживает CACHE_TYPE (N|A|Y) и CACHE_TIME (сек)
 */
final class CacheHelper
{
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Главный метод: оборачивает рендер компонента кэшем.
     */
    public function renderWithCache($component, array $arParams, callable $producer): string
    {
        if((!($component instanceof \App\Component\BaseComponent))) {
            throw new \Exception("renderWithCache ожидает App\View\BaseComponent.");
        }
        // Нормализуем параметры кэша «как в Битрикс»
        $cacheType = strtoupper((string)($arParams['CACHE_TYPE'] ?? 'N'));
        $cacheTime = (int)($arParams['CACHE_TIME'] ?? 0);
        $meta = [];
        // Исключаем служебные ключи, чтобы ключ кэша не «взрывался» от TTL/типа
        $keyParams = $arParams;
        unset($keyParams['CACHE_TYPE'], $keyParams['CACHE_TIME'], $keyParams['CACHE_ID']);
        $meta['hash'] = sha1(json_encode($keyParams, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        // CACHE_TYPE = N или CACHE_TIME <= 0 => без кэша
        if ($cacheType === 'N' || $cacheTime <= 0) {
            return $producer();
        }

        // Папка component/<group>/<name>
        [$group, $name, $metaFile] = $this->detectGroupAndName($component);
        $folder = "component/{$group}/{$name}";
        $meta['mtime'] = $metaFile['mtime']?:0;
        // Ключ включает имя шаблона и параметры
        $tpl    = $component->getTemplateName();
        $key    = $this->makeKey($group, $name, $tpl, $arParams);

        // Чтение
        if ($this->cache->exists($key, $folder, $val)) {
            [$html,$old_meta] = $val;
            $oldHash = $old_meta['hash'] ?? null;
            $oldMtime = $old_meta['mtime'] ?? null;

            if ($oldHash !== null && $oldMtime !== null
                && $oldHash === $meta['hash']
                && $oldMtime === $meta['mtime']) {
                return (string)$html;
            }
        }
        
        // Генерация и запись
        $html = (string)$producer();
        $this->cache->set($key, $folder, $html, $cacheTime, $meta);
        return $html;
    }

    public function renderGeneric(string $template, int $ttl, callable $producer): string
    {
        [$folder, $key, $meta] = $this->makeTemplateCacheKey($template);
        if ($ttl <= 0) {
            return (string)$producer();
        }

        if ($this->cache->exists($key, $folder, $val)) {
            [$html, $old_meta] = $val;
            // если передали mtime и он изменился — перегенерируем
            if (!isset($meta['mtime']) || (is_array($old_meta) && ($old_meta['mtime'] ?? null) === $meta['mtime'])) {
                return (string)$html;
            }
        }

        $html = (string)$producer();
        $this->cache->set($key, $folder, $html, $ttl, $meta);
        return $html;
    }

    protected function makeTemplateCacheKey(string $tplRelPath): array
    {
        $absFile = $this->getUIPath($tplRelPath);
        $rel = ltrim(str_replace('\\', '/', $tplRelPath), '/');
        $parts = explode('/', $rel, 2);

        $hasFirst = count($parts) === 2;
        $first = $hasFirst ? $parts[0] : '';
        $rest  = $hasFirst ? $parts[1] : $parts[0]; // если нет первой папки — берём само имя файла

        $folder = 'templates' . ($first ? '/'.$first : '');
        // ключ без глубоких подпапок: нормализуем остаток и добавляем короткий хэш файла
        $normRest = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $rest);
        $suffix = substr(sha1($absFile), 0, 12);
        $key = $normRest . '.' . $suffix;

        $meta = [
            'file'  => $rel,
            'mtime' => @filemtime($absFile) ?: 0,
        ];

        return [$folder, $key, $meta];
    }


    /**
     * Вычисляет пару <group>, <name> для компонента по его пути шаблона.
     * Ожидается вид: components/<group>/<name>/<template>/
     */
    private function detectGroupAndName($component): array
    {
        // BaseComponent::$folder = 'components/ds/test/.default/'
        $tplFolder = trim($component->getTemplateFolder(), "/\\"); // например: components/ds/test/.default/
        $parts = explode('/', $tplFolder);
        $idx = array_search('components', $parts, true);

        if ($idx === false || !isset($parts[$idx+1], $parts[$idx+2])) {
            throw new \RuntimeException("Ожидаю путь вида components/<group>/<name>/<template>/, пришло: {$tplFolder}");
        }

        $group = $parts[$idx+1];
        $name  = $parts[$idx+2];

        $absTplDir = rtrim($this->getUIPath($tplFolder), '/\\');
        $tplFile   = $absTplDir ? $absTplDir . '/template.php' : '';
        $metaFile  = ['mtime' => (is_file($tplFile) ? @filemtime($tplFile) : 0)];

        return [$group, $name, $metaFile];
    }

    private function makeKey(string $group, string $name, string $tpl, array $arParams): string
    {
        // Пользовательский CACHE_ID можно доклеить к ключу
        $suffix = isset($arParams['CACHE_ID']) ? (string)$arParams['CACHE_ID'] : '';

        return "cmp:{$group}:{$name}:tpl:{$tpl}" . ($suffix ? ":{$suffix}" : '');
    }

    protected function getUIPath($path){
        $hive = F4::instance()->get('UI');
        $roots = $hive ? explode(',', $hive) : [];

        foreach ($roots as $root) {
            $uriPath =  trim($root, '/\\') . '/' . ltrim($path, '/\\');
            $path = SITE_ROOT . $uriPath;
            if (is_file($path) || is_dir($path)) {
                return $path;
            }
        }
        return '';
    }
}

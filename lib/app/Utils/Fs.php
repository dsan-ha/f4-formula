<?php
declare(strict_types=1);

namespace App\Utils;

// Класс для обхода файлов и папок с фильтрацией по файлам и папкам
// Example
// Fs::collect('path/to/folder',
//      $include = ['rel/include/path1/**'],
//      $exclude = ['**/exclude.file','*/exlude_for_root.file'],
//      $excludeFolders = ['rel/exclude_folder/path1']);
final class Fs
{
    public static function ensureDir(string $dir, int $mode = 0775): void
    {
        if (is_dir($dir)) return;
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
    }

    /**
     * Зеркалит дерево src -> dst (внутрь dst), возвращает список записанных файлов (для манифеста).
     * @return array{copied: array<int,string>, backed_up: array<int,string>, skipped: array<int,string>}
     */
    public static function mirror(string $src, string $dst, array $opt = []): array
    {
        $overwrite = (bool)($opt['overwrite'] ?? true);
        $backupDir = (string)($opt['backup_dir'] ?? '');

        $src = rtrim($src, '/\\');
        $dst = rtrim($dst, '/\\');

        if (!is_dir($src)) {
            return ['copied' => [], 'backed_up' => [], 'skipped' => []];
        }

        self::ensureDir($dst);

        $copied = [];
        $backed = [];
        $skipped = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $node) {
            $full = (string)$node->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($full, strlen($src))), '/');
            $target = $dst . '/' . $rel;

            if ($node->isDir()) {
                self::ensureDir($target);
                continue;
            }

            self::ensureDir(dirname($target));

            if (is_file($target) && !$overwrite) {
                $skipped[] = $target;
                continue;
            }

            if (is_file($target) && $backupDir !== '') {
                $backupPath = rtrim($backupDir, '/\\') . '/' . $rel;
                self::ensureDir(dirname($backupPath));
                @copy($target, $backupPath);
                $backed[] = $backupPath;
            }

            if (!@copy($full, $target)) {
                throw new \RuntimeException("Copy failed: {$full} -> {$target}");
            }
            @chmod($target, 0664);
            $copied[] = $target;
        }

        return ['copied' => $copied, 'backed_up' => $backed, 'skipped' => $skipped];
    }

    public static function collect(
        string $root,
        array $include = [],
        array $exclude = [],
        array $excludeFolders = []
    ): array {
        $files = [];

        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (!is_dir($root)) {
            return $files;
        }

        $include = self::normalizeGlobPatterns($include, false);
        $exclude = self::normalizeGlobPatterns($exclude, false);
        $excludeFolders = self::normalizeGlobPatterns($excludeFolders, true);

        $dirIt = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);

        $filter = new \RecursiveCallbackFilterIterator(
            $dirIt,
            function (\SplFileInfo $current) use ($root, $excludeFolders) {
                $rel = self::relPath($current->getPathname(), $root);

                if ($current->isDir()) {
                    return !self::isExcluded($rel, $excludeFolders);
                }

                return true;
            }
        );

        $it = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile()) continue;

            $rel = self::relPath($fileInfo->getPathname(), $root);

            if (!empty($exclude) && self::isExcluded($rel, $exclude)) continue;
            if (!empty($include) && !self::isIncluded($rel, $include)) continue;

            $files[$fileInfo->getPathname()] = $rel;
        }

        return $files;
    }

    public static function removeFile(string $path): void
    {
        if (is_file($path) || is_link($path)) @unlink($path);
    }

    private static function relPath(string $full, string $root): string
    {
        return ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
    }

    private static function isIncluded(string $rel, array $includes): bool
    {
        foreach ($includes as $pat) {
            if (self::matchGlob($rel, $pat)) return true;
        }
        return false;
    }

    private static function isExcluded(string $rel, array $excludes): bool
    {
        foreach ($excludes as $pat) {
            if (self::matchGlob($rel, $pat)) return true;
        }
        return false;
    }

    private static function normalizeGlobPatterns(array $patterns, bool $folders = false): array
    {
        $out = [];

        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', (string)$pattern));
            $pattern = trim($pattern, '/');

            if ($pattern === '') {
                continue;
            }

            $out[] = $pattern;

            // Для папок простая запись vendor/node_modules/upload
            // должна срабатывать на любом уровне вложенности.
            if ($folders && !str_contains($pattern, '/') && !str_contains($pattern, '*')) {
                $out[] = '**/' . $pattern;
                $out[] = $pattern . '/**';
                $out[] = '**/' . $pattern . '/**';
            }
        }

        return array_values(array_unique($out));
    }

    private static function matchGlob(string $rel, string $pattern): bool
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        $pattern = trim(str_replace('\\', '/', $pattern), '/');

        if ($pattern === '') {
            return false;
        }

        $regex = preg_quote($pattern, '~');

        // **/vendor => vendor или any/path/vendor
        $regex = str_replace('\*\*/', '(?:.*/)?', $regex);

        // vendor/** => vendor или vendor/any/path
        $regex = str_replace('/\*\*', '(?:/.*)?', $regex);

        // Остаточные ** и обычные *
        $regex = str_replace('\*\*', '.*', $regex);
        $regex = str_replace('\*', '[^/]*', $regex);

        return (bool)preg_match('~^' . $regex . '$~u', $rel);
    }
}
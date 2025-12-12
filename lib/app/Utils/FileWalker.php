<?php
namespace App\Utils;

// Класс для обхода файлов и папок с фильтрацией по файлам и папкам
// Example
// FileWalker::collect('path/to/folder',
//      $include = ['rel/include/path1/**'],
//      $exclude = ['**/exclude.file','*/exlude_for_root.file'],
//      $excludeFolders = ['rel/exclude_folder/path1']);
class FileWalker
{
    public static function collect(
        string $root,
        array $include = [],
        array $exclude = [],
        array $excludeFolders = []
    ): array {
        $files = [];
        $dirIt = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        self::globPatterns($exclude);
        self::globPatterns($include);
        self::globPatterns($excludeFolders);

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

    private static function globPatterns(&$patterns){
        foreach ($patterns as $key => &$pattern) {
            $pattern = str_replace('\\', '/', $pattern);
            $pattern = str_replace(['.','/'], ['\.','\/'], ltrim($pattern, '/'));
            $ar_replace = [
                '**' => '\1',
                '*' => '[^\/]*',
                '\1' => '.*'
            ]; // Выделены в последовательную замену, так как друг с другом несовместимы в групповой замене
            foreach ($ar_replace as $search => $replace) {
                $pattern = str_replace($search, $replace, $pattern);
            }
        }
    }

    private static function matchGlob(string $rel, string $pattern): bool
    {
        $rel = str_replace('\\', '/', $rel);
        $regex = '/^' . $pattern . '$/u';
        return (bool)preg_match($regex, $rel);
    }
}

<?php
namespace App\Modules;

final class ModuleAutoloader
{
    /** @var array<string,string> prefix => baseDir */
    private array $prefixes = [];

    public function register(int $prepend = 1): void
    {
        spl_autoload_register([$this, 'autoload'], true, (bool)$prepend);
    }

    public function addPsr4(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;

        $this->prefixes[$prefix] = $baseDir;
    }

    public function autoload(string $class): void
    {
        // быстрый выход
        if ($this->prefixes === []) return;

        // ищем самый длинный совпадающий prefix (как у composer)
        $matchPrefix = null;
        foreach ($this->prefixes as $prefix => $dir) {
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                if ($matchPrefix === null || strlen($prefix) > strlen($matchPrefix)) {
                    $matchPrefix = $prefix;
                }
            }
        }
        if ($matchPrefix === null) return;

        $baseDir = $this->prefixes[$matchPrefix];
        $relative = substr($class, strlen($matchPrefix)); // остальная часть после префикса
        $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
}

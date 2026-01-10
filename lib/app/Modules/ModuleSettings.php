<?php
namespace App\Modules;

use App\Modules\ModuleConfigException;

final class ModuleSettings
{
    public string $slug;
    public string $name;
    public string $namespace;
    public bool $active;
    public int $priority = 50;
    public string $include = 'include.php';
    public array $raw = [];

    /** @param array $yaml Parsed YAML */
    public static function fromArray(array $yaml, string $settingsPath): self
    {
        $m = $yaml['module'] ?? null;
        if (!is_array($m)) {
            throw ModuleConfigException::invalid($settingsPath, "Root key 'module' must be an object.");
        }

        $required = [ 'name', 'active', 'namespace'];
        $missing = [];
        foreach ($required as $k) if (!array_key_exists($k, $m)) $missing[] = "module.$k";
        if ($missing) throw ModuleConfigException::missing($settingsPath, $missing);

        if (!is_string($m['name']) || $m['name'] === '') {
            throw ModuleConfigException::invalid($settingsPath, "module.name must be non-empty string.");
        }
        if (!is_bool($m['active'])) {
            throw ModuleConfigException::invalid($settingsPath, "module.active must be boolean.");
        }

        if (!is_string($m['namespace']) || $m['namespace'] === '') {
            throw ModuleConfigException::invalid($settingsPath, "module.namespace must be non-empty string.");
        }

        if (!preg_match('/^(?:[A-Z][A-Za-z0-9_]*)(?:\\\\[A-Z][A-Za-z0-9_]*)*$/', $m['namespace'])) {
            throw ModuleConfigException::invalid($settingsPath, "module.namespace has invalid format.");
        }

        $s = new self();
        $s->name = $m['name'];
        $s->active = $m['active'];
        $s->priority = isset($m['priority']) ? (int)$m['priority'] : 50;
        $s->namespace = $m['namespace'];
        $s->include = 'include.php';
        $s->raw = $yaml;

        return $s;
    }
}

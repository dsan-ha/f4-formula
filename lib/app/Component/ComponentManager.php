<?php

namespace App\Component;

use App\F4;
use App\Utils\Assets;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ComponentManager
{
    protected F4 $f4;
    protected Assets $assets;
    protected string $uiDir = 'components/';

    public function __construct(F4 $f4, Assets $assets)
    {
        $this->f4 = $f4;
        $this->assets = $assets;

        if (!$this->f4->exists('COMPONENTS', $stack) || !is_array($stack)) {
            $this->f4->set('COMPONENTS', []);
        }

        $this->registerLegacyComponents();
        $this->registerModulesFromHive();
    }

    public function run(string $name, string $template = '.default', array $params = []): string
    {
        [$group, $compName] = $this->splitName($name);
        self::checkName($group, $compName, $template);

        $item = $this->getComponent($name);
        if (!$item) {
            throw new \RuntimeException("Component '{$name}' not registered.");
        }

        $className = (string)($item['class'] ?? '');
        if ($className === '' || !class_exists($className)) {
            throw new \RuntimeException("Component class '{$className}' not defined.");
        }

        $templateRoot = rtrim((string)($item['template_root'] ?? ''), '/\\');
        if ($templateRoot === '') {
            $templateRoot = $this->uiDir . $group . '/' . $compName;
        }

        $templateFolder = $templateRoot . '/' . $template . '/';

        $component = new $className($this->f4, $this->assets, $template, $templateFolder, $params);
        $component->execute();

        return $component->render();
    }

    public function getComponent(string $alias): ?array
    {
        $stack = (array)$this->f4->get('COMPONENTS');
        return isset($stack[$alias]) && is_array($stack[$alias]) ? $stack[$alias] : null;
    }

    public function addComponent(string $alias, string $className, ?string $templateRoot = null, array $meta = []): void
    {
        [$group, $compName] = $this->splitName($alias);
        self::checkName($group, $compName, '.default');

        $stack = (array)$this->f4->get('COMPONENTS');

        $stack[$alias] = array_merge([
            'alias'         => $alias,
            'group'         => $group,
            'name'          => $compName,
            'class'         => ltrim($className, '\\'),
            'template_root' => $templateRoot ?: ($this->uiDir . $group . '/' . $compName),
            'source'        => 'runtime',
            'priority'      => 50,
        ], $meta);

        $this->f4->set('COMPONENTS', $stack);
    }

    public function registerModuleComponents(array $module): void
    {
        if (empty($module['active'])) {
            return;
        }

        $slug = (string)($module['slug'] ?? '');
        $namespace = trim((string)($module['namespace'] ?? $slug), '\\');
        $basePath = rtrim((string)($module['base_path'] ?? ''), '/\\');
        $dir = $basePath . '/lib/Component';

        if ($slug === '' || $namespace === '' || !is_dir($dir)) {
            return;
        }

        $this->registerComponentsPath(
            $dir,
            $namespace . '\\Component',
            $slug,
            (int)($module['priority'] ?? 50)
        );
    }

    protected function registerLegacyComponents(): void
    {
        $this->registerComponentsPath(
            SITE_ROOT . 'lib/app/Component',
            'App\\Component',
            null,
            10
        );

        $this->registerComponentsPath(
            SITE_ROOT . 'local/app/Component',
            'App\\Component',
            null,
            100
        );
    }

    protected function registerModulesFromHive(): void
    {
        $modules = (array)$this->f4->get('MODULES');
        if (!$modules) {
            return;
        }

        // меньший приоритет раньше, больший позже, чтобы higher priority мог перезаписать
        uasort($modules, fn(array $a, array $b) => ((int)($a['priority'] ?? 50)) <=> ((int)($b['priority'] ?? 50)));

        foreach ($modules as $module) {
            $this->registerModuleComponents($module);
        }
    }

    protected function registerComponentsPath(
        string $baseDir,
        string $namespacePrefix,
        ?string $fixedGroup = null,
        int $priority = 50
    ): void {
        if (!is_dir($baseDir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
        );

        $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
        $namespacePrefix = trim($namespacePrefix, '\\');

        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $classFile = $file->getBasename('.php');
            if (!str_ends_with($classFile, 'Component')) {
                continue;
            }

            if (in_array($classFile, ['BaseComponent', 'ComponentManager'], true)) {
                continue;
            }

            $fullPath = str_replace('\\', '/', $file->getPathname());
            $rel = ltrim(substr($fullPath, strlen($baseDir)), '/');
            $relNoExt = substr($rel, 0, -4); // without .php
            $parts = array_values(array_filter(explode('/', $relNoExt), 'strlen'));

            if (!$parts) {
                continue;
            }

            $shortClass = array_pop($parts);
            $shortBase = preg_replace('/Component$/', '', $shortClass);
            if ($shortBase === null || $shortBase === '') {
                continue;
            }

            if ($fixedGroup === null) {
                if (!$parts) {
                    continue;
                }

                $group = $this->segmentToAlias(array_shift($parts));
                $nameParts = $parts;
                $nameParts[] = $shortBase;
            } else {
                $group = $fixedGroup;
                $nameParts = $parts;
                $nameParts[] = $shortBase;
            }

            $name = implode('.', array_map([$this, 'segmentToAlias'], $nameParts));
            if ($name === '') {
                continue;
            }

            $className = $namespacePrefix . '\\' . str_replace('/', '\\', $relNoExt);
            $alias = $group . ':' . $name;
            $templateRoot = $this->uiDir . $group . '/' . $name;

            $this->addComponent($alias, $className, $templateRoot, [
                'priority' => $priority,
                'path'     => $fullPath,
            ]);
        }
    }

    protected function splitName(string $name): array
    {
        if (strpos($name, ':') === false) {
            throw new \RuntimeException("Component logic name - 'folder:my.component'.");
        }

        [$group, $compName] = explode(':', $name, 2);

        if ($group === '' || $compName === '') {
            throw new \RuntimeException("Error name component.");
        }

        return [$group, $compName];
    }

    protected function segmentToAlias(string $name): string
    {
        $name = preg_replace('/([a-z0-9])([A-Z])/u', '$1.$2', $name);
        $name = strtolower((string)$name);
        $name = str_replace(['-', '_'], '.', $name);
        $name = preg_replace('/\.+/', '.', $name);
        return trim((string)$name, '.');
    }

    protected static function checkName(string $folder, string $compName, string $template): void
    {
        if (strlen($folder) > 60 || strlen($compName) > 120 || strlen($template) > 40) {
            throw new \InvalidArgumentException("Component name or template too long.");
        }

        if ($folder === '' || $compName === '') {
            throw new \InvalidArgumentException("Component name empty string");
        }

        if (!preg_match('/^[a-z0-9\.]+$/', $folder) || !preg_match('/^[a-z0-9\.]+$/', $compName)) {
            throw new \InvalidArgumentException("Invalid component name or folder name.");
        }

        if (!preg_match('/^[a-zA-Z0-9_\.-]+$/', $template)) {
            throw new \InvalidArgumentException("Invalid component template.");
        }
    }
}
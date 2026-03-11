<?php
declare(strict_types=1);

namespace App\Modules\Install;

use App\F4;
use App\Utils\Fs;
use Symfony\Component\Yaml\Yaml;

final class ModuleInstaller
{
    private F4 $f4;

    public function __construct(F4 $f4)
    {
        $this->f4 = $f4;
    }

    /**
     * @param array<string,array<string,mixed>> $modules slug => descriptor
     */
    public function installMissing(array &$modules): void
    {
        foreach ($modules as $slug => &$m) {
            if (empty($m['active'])) continue;

            $needInstall = !$this->isInstalled($m);
            if (!$needInstall) continue;

            $this->installOne($m);

            // отмечаем установку
            $this->setInstalledFlag($m, true);

            // обновим in-memory settings, чтобы в текущем запросе было консистентно
            $m['settings']['module']['install'] = true;
        }
    }

    /**
     * @param array<string,mixed> $m
     */
    private function installOne(array $m): void
    {
        $base = rtrim((string)($m['base_path'] ?? ''), '/\\');
        if ($base === '' || !is_dir($base)) {
            throw new \RuntimeException("Module base_path invalid for install.");
        }

        // 1) UI шаблоны: Modules/<module>/install/ui -> /ui
        $uiSrc = $base . '/install/ui';
        $uiDst = rtrim(SITE_ROOT, '/\\') . '/ui';

        if(is_dir($uiSrc)){
            $backupDir = rtrim(SITE_ROOT, '/\\') . '/ui/.module_backup/' . (string)($m['slug'] ?? 'unknown') . '/' . date('Ymd_His');
            $res = Fs::mirror($uiSrc, $uiDst, [
                'overwrite' => true,
                'backup_dir' => $backupDir,
            ]);

            // манифест на будущее (для uninstall)
            $this->writeManifest($m, $res['copied']);
        }
        

        // 2) кастомная логика модуля (если есть)
        $entry = $base . '/install/index.php';
        if (is_file($entry)) {
            $installer = $this->loadInstaller($entry, $m);
            $installer->install();
        } else {
            throw new \RuntimeException("In module '". $m['name'] ."' install/index.php not_found.");
        }
    }

    /**
     * @param array<string,mixed> $m
     */
    private function isInstalled(array $m): bool
    {
        $val = $m['settings']['module']['install'] ?? false;
        if (is_int($val)) return (bool)$val;
        return (bool)$val;
    }

    /**
     * @param array<string,mixed> $m
     */
    private function setInstalledFlag(array $m, bool $installed): void
    {
        $settingsPath = (string)($m['settings_path'] ?? '');
        if ($settingsPath === '' || !is_file($settingsPath)) return;

        // если не можем писать (например lib/ только чтение) - просто не падаем
        if (!is_writable($settingsPath)) return;

        $yaml = Yaml::parseFile($settingsPath) ?: [];
        if (!is_array($yaml)) $yaml = [];

        if (!isset($yaml['module']) || !is_array($yaml['module'])) $yaml['module'] = [];
        $yaml['module']['install'] = $installed;

        $dump = Yaml::dump($yaml, 6, 2);
        file_put_contents($settingsPath, $dump, LOCK_EX);
    }

    /**
     * install/index.php должен вернуть:
     * - объект InstallerInterface, или
     * - строку с FQN класса InstallerInterface
     *
     * @param array<string,mixed> $m
     */
    private function loadInstaller(string $entry, array $m): InstallerInterface
    {
        $ret = require $entry;

        if ($ret instanceof InstallerInterface) {
            return $ret;
        }

        if (is_string($ret) && class_exists($ret)) {
            $obj = new $ret($this->f4, $m);
            if (!$obj instanceof InstallerInterface) {
                throw new \RuntimeException("Installer class must implement InstallerInterface: {$ret}");
            }
            return $obj;
        }

        throw new \RuntimeException("install/index.php must return InstallerInterface or class-string.");
    }

    /**
     * @param array<string,mixed> $m
     * @param array<int,string> $files
     */
    private function writeManifest(array $m, array $files): void
    {
        $slug = (string)($m['slug'] ?? 'unknown');
        $dir = rtrim(SITE_ROOT, '/\\') . '/local/data/modules/_install_manifest';
        Fs::ensureDir($dir);

        $payload = [
            'slug' => $slug,
            'installed_at' => date('c'),
            'ui_files' => array_values($files),
        ];

        file_put_contents($dir . '/' . $slug . '.json', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }
}
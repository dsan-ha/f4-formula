<?php
declare(strict_types=1);

namespace App\Modules\Install;

use App\Utils\Fs;
use App\Base\ServiceLocator;
use App\F4;
use App\Migrations\PhinxMigrator;
use Symfony\Component\Yaml\Yaml;

final class ModuleInstaller
{
    private string $backupDir;
    private string $dir_manifest;
    private ServiceLocator $services;
    private F4 $f4;
    private PhinxMigrator $migrator;

    public function __construct(ServiceLocator $services, F4 $f4, PhinxMigrator $migrator)
    {
        $this->services = $services;
        $this->f4 = $f4;
        $this->migrator = $migrator;
        $this->backupDir = rtrim(SITE_ROOT, '/\\') . '/local/tmp/modules/.module_backup';
        $this->dir_manifest = rtrim(SITE_ROOT, '/\\') . '/local/tmp/modules/_install_manifest';
        Fs::ensureDir($this->backupDir);
        Fs::ensureDir($this->dir_manifest);
    }

    /**
     * @param array<string,array<string,mixed>> $modules slug => descriptor
     */
    public function installMissing(array &$modules): void
    {
        foreach ($modules as $slug => &$m) {
            if (empty($m['active'])) continue;

            $updatePermanent = $this->isUpdatePermanent($m);
            $needInstall = $updatePermanent || !$this->isInstalled($m);

            if (!$needInstall) continue;

            $this->installOne($m, $updatePermanent);

            // отмечаем установку
            $this->setInstalledFlag($m, true);

            // обновим in-memory settings, чтобы в текущем запросе было консистентно
            $m['settings']['module']['install'] = true;
        }
    }

    /**
     * @param array<string,mixed> $m
     */
    private function installOne(array $m, bool $updatePermanent = false): void
    {
        $base = rtrim((string)($m['base_path'] ?? ''), '/\\');
        if ($base === '' || !is_dir($base)) {
            throw new \RuntimeException("Module base_path invalid for install.");
        }

        // 1) UI шаблоны: Modules/<module>/install/ui -> /ui
        $uiSrc = $base . '/install/ui';
        $uiDst = rtrim(SITE_ROOT, '/\\') . '/ui';

        if (is_dir($uiSrc)) {
            $mirrorOptions = [
                'overwrite' => true,
            ];

            // В режиме постоянного обновления backup бесполезен и быстро засирает tmp
            if (!$updatePermanent) {
                $mirrorOptions['backup_dir'] =
                    $this->backupDir . '/' . (string)($m['slug'] ?? 'unknown') . '/' . date('Ymd_His');
            }

            $res = Fs::mirror($uiSrc, $uiDst, $mirrorOptions);

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

    private function isUpdatePermanent(array $m): bool
    {
        $val = $m['update_permanent']
            ?? ($m['settings']['module']['update_permanent'] ?? false);

        if (is_int($val)) return (bool)$val;

        return $val === true;
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
        if (!is_array($yaml)) return;

        if (!isset($yaml['module']) || !is_array($yaml['module'])) return;
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

        if ($ret instanceof InstallerInterface || (is_string($ret) && class_exists($ret))) {
            $obj = $this->services->make($ret, [
                'f4' => $this->f4,
                'module' => $m,
                'migrator' => $this->migrator,
            ]);
            if (!$obj instanceof InstallerInterface) {
                $name = is_object($obj) ? $obj::class : (string)$ret;
                throw new \RuntimeException("Installer class must implement InstallerInterface: {$name}");
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
        

        $payload = [
            'slug' => $slug,
            'installed_at' => date('c'),
            'ui_files' => array_values($files),
        ];

        file_put_contents($this->dir_manifest . '/' . $slug . '.json', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }
}

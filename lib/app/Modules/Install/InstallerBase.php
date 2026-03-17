<?php
declare(strict_types=1);

namespace App\Modules\Install;

use App\F4;
use App\Migrations\PhinxMigrator;

abstract class InstallerBase implements InstallerInterface
{
    protected F4 $f4;

    /** @var array<string,mixed> */
    protected array $module;

    public function __construct(F4 $f4, array $module)
    {
        $this->f4 = $f4;
        $this->module = $module;
    }

    protected function slug(): string
    {
        return (string)($this->module['slug'] ?? '');
    }

    protected function basePath(): string
    {
        return rtrim((string)($this->module['base_path'] ?? ''), '/\\');
    }

    protected function installPath(): string
    {
        return $this->basePath() . '/install';
    }

    protected function uiDestPath(): string
    {
        return rtrim(SITE_ROOT, '/\\') . '/ui';
    }

    protected function phinxMigrator(): PhinxMigrator
    {
        if(!$this->f4->exists('PhinxMigrator', $migrator)) {
            $migrator = new PhinxMigrator();
            $this->f4->set('PhinxMigrator', $migrator);
        } 
        return $migrator;
    }

    protected function installModulePhinx(?string $environment = null, bool $runSeeds = false): void
    {
        $result = $this->phinxMigrator()->migrateModule($this->slug(), $environment);

        if (($result['success'] ?? false) !== true) {
            $message = trim((string)($result['output'] ?? ''));
            throw new \RuntimeException(
                'Phinx migrate failed' . ($message !== '' ? ': ' . $message : '')
            );
        }

        if ($runSeeds) {
            $seedResult = $this->phinxMigrator()->seedModule($this->slug(), $environment);

            if (($seedResult['success'] ?? false) !== true) {
                $message = trim((string)($seedResult['output'] ?? ''));
                throw new \RuntimeException(
                    'Phinx seed failed' . ($message !== '' ? ': ' . $message : '')
                );
            }
        }
    }

    protected function rollbackModulePhinx(
        array $args = [],
        ?string $environment = null,
        bool $withSnapshot = true
    ): array {
        $result = $this->phinxMigrator()->rollbackModule(
            $this->slug(),
            $args,
            $environment,
            $withSnapshot
        );

        if (($result['success'] ?? false) !== true) {
            $message = trim((string)($result['output'] ?? ''));
            throw new \RuntimeException(
                'Phinx rollback failed' . ($message !== '' ? ': ' . $message : '')
            );
        }

        return $result;
    }

    protected function modulePhinxStatus(?string $environment = null): array
    {
        return $this->phinxMigrator()->statusModule($this->slug(), $environment);
    }

    protected function restoreModuleSnapshot(string $snapshotPath, ?string $environment = null): array
    {
        return $this->phinxMigrator()->restoreSnapshot($snapshotPath, $environment);
    }
}
<?php
declare(strict_types=1);

namespace App\Modules\Install;

use App\F4;
use App\Migrations\PhinxMigrator;
use App\Base\RuntimeFactoryInterface;

abstract class InstallerBase implements InstallerInterface, RuntimeFactoryInterface
{
    protected F4 $f4;

    /** @var array<string,mixed> */
    protected array $module;
    protected PhinxMigrator $migrator;

    /** @param array<string,mixed> $context */
    final public function setFactoryContext(array $context): void
    {
        $f4 = $context['f4'] ?? null;
        $module = $context['module'] ?? null;
        $migrator = $context['migrator'] ?? null;

        if (!$f4 instanceof F4) {
            throw new \RuntimeException(static::class . ' factory context f4 must be App\F4.');
        }
        if (!is_array($module)) {
            throw new \RuntimeException(static::class . ' factory context module must be array.');
        }
        if (!$migrator instanceof PhinxMigrator) {
            throw new \RuntimeException(static::class . ' factory context migrator must be PhinxMigrator.');
        }

        $this->f4 = $f4;
        $this->module = $module;
        $this->migrator = $migrator;
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
        return $this->migrator;
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

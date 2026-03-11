<?php
declare(strict_types=1);

namespace App\Modules\Install;

use App\F4;
use Phinx\Console\PhinxApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class InstallerBase implements InstallerInterface
{
    protected F4 $f4;
    /** @var array<string,mixed> */
    protected array $module;

    /**
     * @param array<string,mixed> $module Descriptor из ModuleRegistry (slug, base_path, settings_path, namespace...)
     */
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

    protected function phinxConfigPath(): string
    {
        return rtrim(SITE_ROOT, '/\\') . '/lib/phinx.php';
    }

    protected function phinxConfig(): array
    {
        $path = $this->phinxConfigPath();

        if (!is_file($path)) {
            throw new \RuntimeException("Phinx config not found: {$path}");
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new \RuntimeException("Invalid Phinx config: {$path}");
        }

        return $config;
    }

    protected function moduleMigrationsPath(): string
    {
        return $this->basePath() . '/db/migrations';
    }

    protected function moduleSeedsPath(): string
    {
        return $this->basePath() . '/db/seeds';
    }

    protected function globalMigrationsPath(): string
    {
        $config = $this->phinxConfig();
        $path = (string)($config['paths']['migrations'] ?? '');

        if ($path === '') {
            throw new \RuntimeException('Phinx config: paths.migrations is empty');
        }

        return $this->normalizePath($path);
    }

    protected function globalSeedsPath(): string
    {
        $config = $this->phinxConfig();
        $path = (string)($config['paths']['seeds'] ?? '');

        if ($path === '') {
            throw new \RuntimeException('Phinx config: paths.seeds is empty');
        }

        return $this->normalizePath($path);
    }

    protected function hasModuleMigrations(): bool
    {
        return $this->hasPhpFiles($this->moduleMigrationsPath());
    }

    protected function hasModuleSeeds(): bool
    {
        return $this->hasPhpFiles($this->moduleSeedsPath());
    }

    protected function copyMigrationsToGlobalDir(): bool
    {
        return $this->copyPhpFiles(
            $this->moduleMigrationsPath(),
            $this->globalMigrationsPath(),
            'migration'
        );
    }

    protected function copySeedsToGlobalDir(): bool
    {
        return $this->copyPhpFiles(
            $this->moduleSeedsPath(),
            $this->globalSeedsPath(),
            'seed'
        );
    }

    protected function installModulePhinx(?string $environment = null): void
    {
        $hasMigrations = $this->hasModuleMigrations();
        $hasSeeds = $this->hasModuleSeeds();

        if (!$hasMigrations && !$hasSeeds) {
            return;
        }

        if ($hasMigrations) {
            $this->copyMigrationsToGlobalDir();
        }

        if ($hasSeeds) {
            $this->copySeedsToGlobalDir();
        }

        if ($hasMigrations) {
            $result = $this->runPhinxCommand('migrate', $environment);

            if (($result['success'] ?? false) !== true) {
                $message = trim((string)($result['output'] ?? ''));
                throw new \RuntimeException(
                    'Phinx migrate failed' . ($message !== '' ? ': ' . $message : '')
                );
            }
        }
    }

    protected function runPhinxCommand(string $command, ?string $environment = null, array $extra = []): array
    {
        $app = new PhinxApplication();
        $app->setAutoExit(false);

        $args = array_merge([
            'command' => $command,
            '--configuration' => $this->phinxConfigPath(),
        ], $extra);

        $env = $environment ?: $this->defaultPhinxEnvironment();
        if ($env !== '') {
            $args['--environment'] = $env;
        }

        $input = new ArrayInput($args);
        $output = new BufferedOutput();

        try {
            $exitCode = $app->run($input, $output);
            $text = $output->fetch();

            return [
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => $text,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'exit_code' => 1,
                'output' => $output->fetch() . "\n" . $e->getMessage(),
            ];
        }
    }

    protected function defaultPhinxEnvironment(): string
    {
        $config = $this->phinxConfig();
        return (string)($config['environments']['default_environment'] ?? 'development');
    }

    protected function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \RuntimeException('Empty path');
        }

        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) {
            $path = rtrim(SITE_ROOT, '/\\') . '/' . ltrim($path, '/\\');
        }

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    protected function hasPhpFiles(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = glob(rtrim($dir, '/\\') . '/*.php') ?: [];
        return !empty($files);
    }

    protected function copyPhpFiles(string $fromDir, string $toDir, string $type): bool
    {
        if (!is_dir($fromDir)) {
            return false;
        }

        if (!is_dir($toDir) && !mkdir($toDir, 0775, true) && !is_dir($toDir)) {
            throw new \RuntimeException("Cannot create {$type} directory: {$toDir}");
        }

        $files = glob(rtrim($fromDir, '/\\') . '/*.php') ?: [];
        $copied = false;

        foreach ($files as $src) {
            if (!is_file($src)) {
                continue;
            }

            $baseName = basename($src);
            $dst = rtrim($toDir, '/\\') . '/' . $baseName;

            if (!is_file($dst)) {
                if (!copy($src, $dst)) {
                    throw new \RuntimeException("Failed to copy {$type}: {$src} -> {$dst}");
                }
                $copied = true;
                continue;
            }

            $srcHash = sha1_file($src);
            $dstHash = sha1_file($dst);

            if ($srcHash === $dstHash) {
                continue;
            }

            throw new \RuntimeException(
                ucfirst($type) . " conflict: {$baseName} already exists in global directory with different content"
            );
        }

        return $copied;
    }
}
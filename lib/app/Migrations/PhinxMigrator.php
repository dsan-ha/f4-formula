<?php
declare(strict_types=1);

namespace App\Migrations;

use App\F4;
use PDO;
use Phinx\Console\PhinxApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PhinxMigrator
{
    protected string $configPath;
    protected string $snapshotDir;
    protected F4 $f4;
    protected PhinxApplication $phinxApp;

    public function __construct(?string $configPath = null, ?string $snapshotDir = null)
    {
        $root = defined('SITE_ROOT')
            ? rtrim((string) SITE_ROOT, '/\\')
            : dirname(__DIR__, 3);

        $this->configPath = $configPath ?: $root . '/lib/phinx.php';
        $this->f4 = F4::instance();
        $dir = $this->f4->g('migrator_snapshot_dir', 'local/tmp/migrator/snapshots');
        $this->snapshotDir = $snapshotDir ?: $root . '/'. trim($dir,'\\/');

        $this->phinxApp = new PhinxApplication();
        $this->phinxApp->setAutoExit(false);
    }

    public function run(
        string $command = 'migrate',
        array $args = [],
        ?string $environment = null,
        ?string $configPath = null
    ): array {
        $inputArgs = array_merge([
            'command' => $command,
            '--configuration' => $configPath ?: $this->configPath,
        ], $args);

        $env = $environment ?: $this->defaultEnvironment();
        if ($env !== '') {
            $inputArgs['--environment'] = $env;
        }

        $input = new ArrayInput($inputArgs);
        $output = new BufferedOutput();

        try {
            $exitCode = $this->phinxApp->run($input, $output);
            $text = $output->fetch();

            return [
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => $text,
                'command' => $command,
                'environment' => $env,
                'config' => $configPath ?: $this->configPath,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'exit_code' => 1,
                'output' => $output->fetch() . "\n" . $e->getMessage(),
                'command' => $command,
                'environment' => $env,
                'config' => $configPath ?: $this->configPath,
            ];
        }
    }

    public function migrate(array $args = [], ?string $environment = null): array
    {
        return $this->run('migrate', $args, $environment);
    }

    public function status(array $args = [], ?string $environment = null): array
    {
        return $this->run('status', $args, $environment);
    }

    public function seed(array $args = [], ?string $environment = null): array
    {
        return $this->run('seed:run', $args, $environment);
    }

    public function rollback(array $args = [], ?string $environment = null, bool $withSnapshot = true): array
    {
        $snapshot = null;

        if ($withSnapshot) {
            $snapshot = $this->createSnapshot($environment, 'before_rollback');
        }

        $result = $this->run('rollback', $args, $environment);

        if ($snapshot !== null) {
            $result['snapshot'] = $snapshot;
        }

        return $result;
    }

    public function migrateModule(string $slug, ?string $environment = null): array
    {
        $configPath = $this->writeModuleConfig($slug, $environment);

        try {
            $basePath = $this->moduleBasePath($slug);

            if (!$this->hasModuleMigrations($basePath)) {
                return [
                    'success' => true,
                    'exit_code' => 0,
                    'output' => "No migrations for module {$slug}",
                    'command' => 'migrate',
                    'environment' => $environment ?: $this->defaultEnvironment(),
                ];
            }

            return $this->run('migrate', [], $environment, $configPath);
        } finally {
            $this->removeTempConfig($configPath);
        }
    }

    public function statusModule(string $slug, ?string $environment = null): array
    {
        $configPath = $this->writeModuleConfig($slug, $environment);

        try {
            return $this->run('status', [], $environment, $configPath);
        } finally {
            $this->removeTempConfig($configPath);
        }
    }

    public function seedModule(string $slug, ?string $environment = null): array
    {
        $configPath = $this->writeModuleConfig($slug, $environment);

        try {
            $basePath = $this->moduleBasePath($slug);

            if (!$this->hasModuleSeeds($basePath)) {
                return [
                    'success' => true,
                    'exit_code' => 0,
                    'output' => "No seeds for module {$slug}",
                    'command' => 'seed:run',
                    'environment' => $environment ?: $this->defaultEnvironment(),
                ];
            }

            return $this->run('seed:run', [], $environment, $configPath);
        } finally {
            $this->removeTempConfig($configPath);
        }
    }

    public function rollbackModule(
        string $slug,
        array $args = [],
        ?string $environment = null,
        bool $withSnapshot = true
    ): array {
        $snapshot = null;

        if ($withSnapshot) {
            $snapshot = $this->createSnapshot($environment, 'before_rollback_' . $slug);
        }

        $configPath = $this->writeModuleConfig($slug, $environment);

        try {
            $result = $this->run('rollback', $args, $environment, $configPath);

            if ($snapshot !== null) {
                $result['snapshot'] = $snapshot;
            }

            return $result;
        } finally {
            $this->removeTempConfig($configPath);
        }
    }

    protected function moduleDescriptor(string $slug): array
    {
        $modules = (array) $this->f4->get('MODULES');
        $module = $modules[$slug] ?? null;

        if (!is_array($module)) {
            throw new \RuntimeException("Module not found: {$slug}");
        }

        return $module;
    }

    protected function moduleBasePath(string $slug): string
    {
        $module = $this->moduleDescriptor($slug);
        $basePath = rtrim((string)($module['base_path'] ?? ''), '/\\');

        if ($basePath === '' || !is_dir($basePath)) {
            throw new \RuntimeException("Invalid module base_path for {$slug}");
        }

        return $basePath;
    }

    protected function moduleNamespace(string $slug): string
    {
        $module = $this->moduleDescriptor($slug);
        return trim((string)($module['namespace'] ?? $slug), '\\');
    }

    protected function moduleMigrationTable(string $slug): string
    {
        $safe = strtolower((string)preg_replace('/[^a-zA-Z0-9_]+/', '_', $slug));
        return 'phinxlog_' . trim($safe, '_');
    }

    protected function moduleConfig(string $slug, ?string $environment = null): array
    {
        $config = $this->phinxConfig();
        $env = $environment ?: $this->defaultEnvironment();

        if (empty($config['environments'][$env]) || !is_array($config['environments'][$env])) {
            throw new \RuntimeException("Phinx environment not found: {$env}");
        }

        $basePath = $this->moduleBasePath($slug);
        $namespace = $this->moduleNamespace($slug);

        $migrationPaths = [];
        $seedPaths = [];

        $moduleMigrations = $this->moduleMigrationsPath($basePath);
        $moduleSeeds = $this->moduleSeedsPath($basePath);

        if (is_dir($moduleMigrations)) {
            $migrationPaths[$namespace . '\\Migrations'] = $moduleMigrations;
        }

        if (is_dir($moduleSeeds)) {
            $seedPaths[$namespace . '\\Seeds'] = $moduleSeeds;
        }

        $config['paths']['migrations'] = $migrationPaths;
        $config['paths']['seeds'] = $seedPaths;
        $config['migration_base_class'] = ModuleMigration::class;
        $config['environments'][$env]['migration_table'] = $this->moduleMigrationTable($slug);

        return $config;
    }

    protected function writeModuleConfig(string $slug, ?string $environment = null): string
    {
        $config = $this->moduleConfig($slug, $environment);

        $dir = rtrim(SITE_ROOT, '/\\') . '/local/tmp/phinx';
        $this->ensureDir($dir);

        $file = $dir . '/phinx_' . $slug . '_' . uniqid('', true) . '.php';
        $export = "<?php\nreturn " . var_export($config, true) . ";\n";

        if (file_put_contents($file, $export, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write temp phinx config: {$file}");
        }

        return $file;
    }

    protected function removeTempConfig(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function hasModuleMigrations(string $moduleBasePath): bool
    {
        return $this->hasPhpFiles($this->moduleMigrationsPath($moduleBasePath));
    }

    public function hasModuleSeeds(string $moduleBasePath): bool
    {
        return $this->hasPhpFiles($this->moduleSeedsPath($moduleBasePath));
    }

    public function defaultEnvironment(): string
    {
        $config = $this->phinxConfig();
        return (string)($config['environments']['default_environment'] ?? 'development');
    }

    protected function phinxConfig(): array
    {
        if (!is_file($this->configPath)) {
            throw new \RuntimeException("Phinx config not found: {$this->configPath}");
        }

        $config = require $this->configPath;

        if (!is_array($config)) {
            throw new \RuntimeException("Invalid Phinx config: {$this->configPath}");
        }

        return $config;
    }

    protected function environmentConfig(string $environment): array
    {
        $config = $this->phinxConfig();
        $envConfig = $config['environments'][$environment] ?? null;

        if (!is_array($envConfig)) {
            throw new \RuntimeException("Phinx environment not found: {$environment}");
        }

        return $envConfig;
    }

    protected function pdoForEnvironment(string $environment): PDO
    {
        $cfg = $this->environmentConfig($environment);
        $adapter = strtolower((string)($cfg['adapter'] ?? ''));

        if (!in_array($adapter, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException("Snapshot supports only mysql/mariadb, got: {$adapter}");
        }

        $host = (string)($cfg['host'] ?? '127.0.0.1');
        $port = (int)($cfg['port'] ?? 3306);
        $name = (string)($cfg['name'] ?? '');
        $user = (string)($cfg['user'] ?? '');
        $pass = (string)($cfg['pass'] ?? '');
        $charset = (string)($cfg['charset'] ?? 'utf8mb4');

        if ($name === '') {
            throw new \RuntimeException("Phinx environment '{$environment}' has empty DB name");
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $name,
            $charset
        );

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    protected function moduleMigrationsPath(string $moduleBasePath): string
    {
        return rtrim($moduleBasePath, '/\\') . '/db/migrations';
    }

    protected function moduleSeedsPath(string $moduleBasePath): string
    {
        return rtrim($moduleBasePath, '/\\') . '/db/seeds';
    }

    protected function hasPhpFiles(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = glob(rtrim($dir, '/\\') . '/*.php') ?: [];
        return !empty($files);
    }

    protected function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
    }

    protected function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    protected function tableColumns(PDO $pdo, string $table): array
    {
        $quotedTable = $this->quoteIdentifier($table);
        $rows = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_values(array_map(
            static fn(array $row): string => (string)$row['Field'],
            $rows
        ));
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function createSnapshot(?string $environment = null, ?string $label = null): array
    {
        $env = $environment ?: $this->defaultEnvironment();
        $pdo = $this->pdoForEnvironment($env);
        $config = $this->environmentConfig($env);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $payload = [
            'meta' => [
                'created_at' => date('c'),
                'environment' => $env,
                'database' => (string)($config['name'] ?? ''),
                'label' => $label ?: 'snapshot',
            ],
            'tables' => [],
        ];

        foreach ($tables as $table) {
            $table = (string)$table;
            $quotedTable = $this->quoteIdentifier($table);

            $columnsInfo = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $columns = array_values(array_map(
                static fn(array $row): string => (string)$row['Field'],
                $columnsInfo
            ));

            $primaryInfo = $pdo->query("SHOW KEYS FROM {$quotedTable} WHERE Key_name = 'PRIMARY'")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $primary = array_values(array_map(
                static fn(array $row): string => (string)$row['Column_name'],
                $primaryInfo
            ));

            $createInfo = $pdo->query("SHOW CREATE TABLE {$quotedTable}")
                ->fetch(PDO::FETCH_ASSOC) ?: [];

            $createSql = '';
            foreach ($createInfo as $key => $value) {
                if (stripos((string)$key, 'Create Table') !== false) {
                    $createSql = (string)$value;
                    break;
                }
            }

            $rows = $pdo->query("SELECT * FROM {$quotedTable}")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $payload['tables'][$table] = [
                'columns' => $columns,
                'primary' => $primary,
                'create_sql' => $createSql,
                'row_count' => count($rows),
                'rows' => $rows,
            ];
        }

        $this->ensureDir($this->snapshotDir);

        $dbName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)($config['name'] ?? 'db'));
        $labelSafe = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)($label ?: 'snapshot'));
        $fileName = date('Ymd_His') . '_' . $dbName . '_' . $labelSafe . '.json';
        $path = rtrim($this->snapshotDir, '/\\') . '/' . $fileName;

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new \RuntimeException('Failed to encode DB snapshot');
        }

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write snapshot: {$path}");
        }

        return [
            'path' => $path,
            'file' => $fileName,
            'tables' => count($payload['tables']),
            'environment' => $env,
        ];
    }

    public function restoreSnapshot(string $snapshotPath, ?string $environment = null): array
    {
        if (!is_file($snapshotPath)) {
            throw new \RuntimeException("Snapshot not found: {$snapshotPath}");
        }

        $raw = file_get_contents($snapshotPath);
        $data = json_decode((string)$raw, true);

        if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
            throw new \RuntimeException("Invalid snapshot format: {$snapshotPath}");
        }

        $env = $environment ?: $this->defaultEnvironment();
        $pdo = $this->pdoForEnvironment($env);

        $restoredTables = 0;
        $skippedTables = 0;
        $restoredRows = 0;
        $skippedRows = 0;

        try {
            $pdo->beginTransaction();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec('SET UNIQUE_CHECKS = 0');

            foreach ($data['tables'] as $table => $tableData) {
                $table = (string)$table;

                if (!$this->tableExists($pdo, $table)) {
                    $skippedTables++;
                    continue;
                }

                $currentColumns = $this->tableColumns($pdo, $table);
                $snapshotColumns = array_values((array)($tableData['columns'] ?? []));
                $commonColumns = array_values(array_intersect($snapshotColumns, $currentColumns));

                $rows = (array)($tableData['rows'] ?? []);
                if (!$commonColumns || !$rows) {
                    $skippedTables++;
                    $skippedRows += count($rows);
                    continue;
                }

                $primary = array_values(array_intersect(
                    (array)($tableData['primary'] ?? []),
                    $commonColumns
                ));

                $quotedTable = $this->quoteIdentifier($table);
                $quotedColumns = implode(', ', array_map([$this, 'quoteIdentifier'], $commonColumns));
                $placeholders = implode(', ', array_fill(0, count($commonColumns), '?'));

                $sql = "INSERT INTO {$quotedTable} ({$quotedColumns}) VALUES ({$placeholders})";

                $updateColumns = array_values(array_diff($commonColumns, $primary));
                if ($primary && $updateColumns) {
                    $updates = implode(', ', array_map(
                        fn(string $col): string => $this->quoteIdentifier($col) . ' = VALUES(' . $this->quoteIdentifier($col) . ')',
                        $updateColumns
                    ));
                    $sql .= " ON DUPLICATE KEY UPDATE {$updates}";
                } elseif ($primary && !$updateColumns) {
                    $sql = "INSERT IGNORE INTO {$quotedTable} ({$quotedColumns}) VALUES ({$placeholders})";
                }

                $stmt = $pdo->prepare($sql);

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        $skippedRows++;
                        continue;
                    }

                    $values = [];
                    foreach ($commonColumns as $column) {
                        $values[] = $row[$column] ?? null;
                    }

                    $stmt->execute($values);
                    $restoredRows++;
                }

                $restoredTables++;
            }

            $pdo->exec('SET UNIQUE_CHECKS = 1');
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                $pdo->exec('SET UNIQUE_CHECKS = 1');
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable) {
            }

            throw $e;
        }

        return [
            'success' => true,
            'snapshot' => $snapshotPath,
            'environment' => $env,
            'restored_tables' => $restoredTables,
            'skipped_tables' => $skippedTables,
            'restored_rows' => $restoredRows,
            'skipped_rows' => $skippedRows,
        ];
    }
}
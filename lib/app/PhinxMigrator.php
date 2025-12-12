<?php

namespace App;

use Phinx\Console\PhinxApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PhinxMigrator
{
    protected $configPath;
    protected $phinxApp;

    public function __construct($configPath = null)
    {
        $this->configPath = $configPath ?: dirname(__DIR__) . '/phinx.php';
        $this->phinxApp = new PhinxApplication();
        $this->phinxApp->setAutoExit(false);
    }

    /**
     * Выполнить миграцию.
     * @param string $command migrate | rollback | status | other команды Phinx
     * @param array $args дополнительные аргументы (например, версия, среда)
     * @return string вывод команды
     */
    public function run($command = 'migrate', $args = [])
    {
        $inputArgs = array_merge([
            'command' => $command,
            '--configuration' => $this->configPath,
        ], $args);

        $input = new ArrayInput($inputArgs);
        $output = new BufferedOutput();

        $this->phinxApp->run($input, $output);

        return $output->fetch();
    }

    /**
     * Удобные методы для основных команд:
     */
    public function migrate($args = [])    { return $this->run('migrate', $args); }
    public function rollback($args = [])   { return $this->run('rollback', $args); }
    public function status($args = [])     { return $this->run('status', $args); }
    public function seed($args = [])       { return $this->run('seed:run', $args); }
}

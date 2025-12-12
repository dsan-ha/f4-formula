<?php

namespace App\Utils;

use GO\Scheduler as GoScheduler;

class Scheduler
{
    protected Scheduler $scheduler;

    public function __construct()
    {
        $this->scheduler = new GoScheduler();
    }

    public function add(callable $callback, string $expression): void
    {
        $this->scheduler->call($callback)->at($expression);
    }

    public function addCommand(string $command, string $expression): void
    {
        $this->scheduler->php($command)->at($expression);
    }

    public function run(): void
    {
        $this->scheduler->run();
    }
}

<?php

namespace App\Http;

use App\Http\Router;

final class MiddlewareState
{
    const BEFORE = 10;
    const MAIN   = 20;
    const AFTER  = 30;

    public int $stage;
    public int $priority;
    public int $typesMask; // Router::REQ_SYNC | Router::REQ_AJAX | Router::REQ_CLI
    public int $arity = 4; // аргументов middleware (для hook-режима)

    public function __construct(
        $stage = self::MAIN, $priority = 0, $typesMask = null
    ) {
         $this->stage = (int)$stage;
        $this->priority = (int)$priority;

        if ($typesMask === null) {
            $this->typesMask = (Router::REQ_SYNC | Router::REQ_AJAX | Router::REQ_CLI);
        } else {
            $this->typesMask = (int)$typesMask;
        }
    }

    public static function before(int $priority = 0, ?int $typesMask = null): self
    {
        return new self(self::BEFORE, $priority, $typesMask);
    }

    public static function main(int $priority = 0, ?int $typesMask = null): self
    {
        return new self(self::MAIN, $priority, $typesMask);
    }

    public static function after(int $priority = 0, ?int $typesMask = null): self
    {
        return new self(self::AFTER, $priority, $typesMask);
    }
}

<?php

namespace App\Utils\Log;

class LogLevel
{
    public const DEBUG    = 0;
    public const INFO     = 1;
    public const WARNING  = 2;
    public const ERROR    = 3;
    public const CRITICAL = 4;

    public static array $labels = [
        self::DEBUG    => 'DEBUG',
        self::INFO     => 'INFO',
        self::WARNING  => 'WARNING',
        self::ERROR    => 'ERROR',
        self::CRITICAL => 'CRITICAL',
    ];

    public static array $colors = [
        self::DEBUG    => "\033[0;36m",  // Cyan
        self::INFO     => "\033[0;32m",  // Green
        self::WARNING  => "\033[1;33m",  // Yellow
        self::ERROR    => "\033[0;31m",  // Red
        self::CRITICAL => "\033[1;31m",  // Bright Red
    ];

    public const COLOR_RESET = "\033[0m";
}

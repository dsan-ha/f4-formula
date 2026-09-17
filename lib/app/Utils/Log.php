<?php

namespace App\Utils;

use App\F4;
use App\Utils\Log\LogLevel;
use App\Utils\Log\LogNotifier;
use App\Utils\Log\LogRotator;

class Log
{
    protected const LOG_FOLDER = 'lib/tmp/logs/';
    protected string $basePath;
    protected int $threshold;
    protected bool $colorOutput;
    protected bool $jsonFormat;
    protected const ROTATION = true;

    protected ?LogNotifier $notifier = null;
    protected F4 $f4;

    public function __construct(string $filePath, string $logFolder = '', int $threshold = LogLevel::DEBUG, bool $colorOutput = false, bool $jsonFormat = false, ?F4 $f4 = null)
    {
        if (!$f4) {
            throw new \LogicException('Implicit global F4 lookup was removed. Pass App\\F4 as the 6th constructor argument or build the logger in a DI-aware factory.');
        }
        $this->f4 = $f4;
        if(empty($logFolder))
            $logFolder = $f4->g('log.log_folder',self::LOG_FOLDER);
        $this->basePath = SITE_ROOT.$logFolder.$filePath;
        $this->threshold = $threshold;
        $this->colorOutput = $colorOutput;
        $this->jsonFormat = $jsonFormat;
    }

    public function write(string $message, int $level = LogLevel::INFO): void
    {
        $f4 = $this->f4;
        if ($level < $this->threshold) {
            return;
        }

        $label = LogLevel::$labels[$level] ?? 'INFO';

        $logLine = self::formatLine($message, $level, $this->jsonFormat);

        file_put_contents($this->basePath, $logLine, FILE_APPEND | LOCK_EX);

        if (php_sapi_name() === 'cli') {
            $this->outputToConsole($logLine, $level);
        }

        if (self::ROTATION) {
            LogRotator::rotateFile($this->basePath, $this->f4); 
        }

        $logNotify = $f4->get('log_notifier.notifier_on');
        if ($logNotify) {
            LogNotifier::notify($message, $level, $this->f4); 
        }
    }

    public static function writeIn(string $filePath, string $message, int $level = LogLevel::INFO, string $logFolder = '', ?F4 $f4 = null): void
    {
        if (!$f4) {
            throw new \LogicException('Implicit global F4 lookup was removed. Pass App\\F4 as the 5th argument or use a DI-managed logger.');
        }
        if(empty($logFolder))
            $logFolder = $f4->g('log.log_folder',self::LOG_FOLDER);
        $label = LogLevel::$labels[$level] ?? 'INFO';
        $path = SITE_ROOT.$logFolder.$filePath;
        $jsonFormat = false;
        $logLine = self::formatLine($message, $level, $jsonFormat);
        file_put_contents($path, $logLine, FILE_APPEND | LOCK_EX);

        if (self::ROTATION) {
            LogRotator::rotateFile($path, $f4);
        }
        
        $logNotify = $f4->get('log_notifier.notifier_on');
        if ($logNotify) {
            LogNotifier::notify($message, $level, $this->f4); 
        }
    }

    protected static function formatLine(string $message, int $level, bool $jsonFormat): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $label = LogLevel::$labels[$level] ?? 'INFO';

        if ($jsonFormat) {
            return json_encode([
                'time' => $timestamp,
                'level' => $label,
                'message' => $message
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }

        return "[{$timestamp}] {$label}: {$message}" . PHP_EOL;
    }

    protected function outputToConsole(string $line, int $level): void
    {
        if (!$this->colorOutput || !isset(LogLevel::$colors[$level])) {
            echo $line;
            return;
        }

        echo LogLevel::$colors[$level] . rtrim($line) . LogLevel::COLOR_RESET . PHP_EOL;
    }

    public static function getLastLines($filePath, $lines = 10) {
        if (!file_exists($filePath)) {
            return "Файл не найден";
        }
        $file = fopen($filePath, 'r');
        fseek($file, 0, SEEK_END);
        
        $position = ftell($file);
        $currentLine = 0;
        $result = [];
        
        // Читаем файл с конца
        while ($currentLine < $lines && $position >= 0) {
            fseek($file, $position);
            $char = fgetc($file);
            
            if ($char === "\n") {
                if ($position !== ftell($file)) { // Исключаем дублирование последней строки
                    $currentLine++;
                }
            }
            
            $position--;
        }
        
        fseek($file, $position + 2);
        while (!feof($file)) {
            $result[] = fgets($file);
        }
        fclose($file);
        
        return $result;
    }
}


<?php
namespace App\Modules;

final class ModuleConfigException extends \RuntimeException
{
    public static function missing(string $modulePath, array $missingKeys): self
    {
        return new self("Module settings.yaml invalid at {$modulePath}. Missing keys: ".implode(', ', $missingKeys));
    }

    public static function invalid(string $modulePath, string $message): self
    {
        return new self("Module settings.yaml invalid at {$modulePath}. {$message}");
    }
}

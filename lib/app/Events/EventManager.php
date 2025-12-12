<?php

namespace App\Events;

use App\F4;

/**
 * Минимальный реестр обработчиков
 */
final class EventManager
{
    private array $map = [];
    private int $seq = 0;
    private F4 $f4;

    public function __construct(F4 $f4) {
        $this->f4 = $f4;
    }

    public function addEventHandler(
        string $module,
        string $event,
        callable|string|array $handler,
        int $priority = 500
    ): string {
        $id = sprintf('%s.%s#%d.%d', $module, $event, $priority, ++$this->seq);
        $this->map[$module][$event][] = [
            'id'       => $id,
            'priority' => $priority,
            'handler'  => $handler,
            'seq'      => $this->seq,
        ];
        return $id;
    }

    public function removeEventHandler(string $module, string $event, string $handlerId): bool
    {
        if (empty($this->map[$module][$event])) return false;
        $before = count($this->map[$module][$event]);
        $this->map[$module][$event] = array_values(array_filter(
            $this->map[$module][$event],
            fn($r) => $r['id'] !== $handlerId
        ));
        return count($this->map[$module][$event]) < $before;
    }

    public function findEventHandlers(string $module, string $event): array
    {
        $list = $this->map[$module][$event] ?? [];
        usort($list, fn($a,$b) => ($b['priority'] <=> $a['priority']) ?: ($a['seq'] <=> $b['seq']));
        // Возвращаем компактно: ID, CALLBACK, PRIORITY
        return array_map(
            fn($r) => ['ID'=>$r['id'], 'CALLBACK'=>$r['handler'], 'PRIORITY'=>$r['priority']],
            $list
        );
    }

    /** Публичный invoke: единая точка вызова (через F4->call, если есть). */
    public function invoke(callable|string|array $cb, array $args): mixed
    {
        if ($this->f4 && method_exists($this->f4, 'call')) {
            return $this->f4->call($cb, $args);
        }
        if (is_string($cb) && str_contains($cb, '::')) {
            [$class, $method] = explode('::', $cb, 2);
            return call_user_func_array([$class, $method], $args);
        }
        return call_user_func_array($cb, $args);
    }
}


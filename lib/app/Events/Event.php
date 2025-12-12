<?php

namespace App\Events;

use App\F4;

/**
 * $event = new Event('mymodule','OnSomething', [$arg1, $arg2]);
 * $event->send();
 * foreach ($event->getResults() as $r) { ... }
 */
final class Event
{
    /** @var EventResult[] */
    private array $results = [];
    private EventManager $manager;

    public function __construct(
        private string $module,
        private string $event,
        private array $parameters = [],
    ) {
        $f4 = F4::instance();
        $manager = $f4->getDI(EventManager::class);
        $this->manager = $manager;
    }

    public function send(): void
    {
        $this->results = [];
        foreach ($this->manager->findEventHandlers($this->module, $this->event) as $rec) {
            $ret = $this->manager->invoke($rec['CALLBACK'], $this->parameters);

            // Нормализация результата:
            // - Если вернули EventResult — принимаем как есть
            // - false -> ERROR
            // - null  -> UNDEFINED
            // - всё остальное -> SUCCESS с параметрами = возврат
            $res =
                $ret instanceof EventResult ? $ret :
                ($ret === false ? EventResult::error(null, 'Handler returned false') :
                ($ret === null ? EventResult::undefined() : EventResult::success($ret)));

            $this->results[] = $res->withHandlerId($rec['ID']);
        }
    }

    public function getResults(): array        { return $this->results; }
    public function getModule(): string        { return $this->module; }
    public function getEvent(): string         { return $this->event; }
    public function getParameters(): array     { return $this->parameters; }
}

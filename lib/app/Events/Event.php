<?php

namespace App\Events;


/**
 * Создание Event теперь должно идти через EventManager::event(), либо с явной
 * передачей EventManager четвёртым аргументом.
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
        ?EventManager $manager = null,
    ) {
        if (!$manager) {
            throw new \LogicException('Implicit F4/DI lookup was removed. Inject EventManager and use $eventManager->event($module, $event, $parameters), or pass EventManager as the 4th constructor argument.');
        }
        $this->manager = $manager;
    }

    public function send(): void
    {
        $this->results = [];
        foreach ($this->manager->findEventHandlers($this->module, $this->event) as $rec) {
            $ret = $this->manager->invoke($rec['CALLBACK'], $this->parameters);

            $res =
                $ret instanceof EventResult ? $ret :
                ($ret === false ? EventResult::error(null, 'Handler returned false') :
                ($ret === null ? EventResult::undefined() : EventResult::success($ret)));

            $this->results[] = $res->withHandlerId($rec['ID']);
        }
    }

    public function getResults(): array { return $this->results; }
    public function getModule(): string { return $this->module; }
    public function getEvent(): string { return $this->event; }
    public function getParameters(): array { return $this->parameters; }
}

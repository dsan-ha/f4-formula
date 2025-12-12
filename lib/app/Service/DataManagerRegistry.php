<?php

namespace App\Service;

use App\F4;
use App\Service\DB\SQL;
use App\Service\DataEntityInterface;  
use App\Service\Hydrator\HydratorInterface;
use App\Service\Hydrator\MapHydrator;       
use App\Utils\Cache as BaseCache;       
use InvalidArgumentException;

class DataManagerRegistry
{
    private array $managers = [];
    private array $hydrators = [];
    protected SQL $db;
    protected F4 $f4;
    protected BaseCache $cache;

    public function __construct(SQL $db, F4 $f4, BaseCache $cache)
    {
        $this->db = $db;
        $this->f4 = $f4;
        $this->cache = $cache;
    }

    public function setHydrator(string $entityClass, HydratorInterface|callable $hydratorOrFactory): void
    {
        $this->hydrators[$entityClass] = $hydratorOrFactory;
    }

    public function getHydrator(string $entityClass): HydratorInterface
    {
        if (isset($this->hydrators[$entityClass])) {
            $h = $this->hydrators[$entityClass];
            $hydrator = is_callable($h) ? $h($this->db, $this->f4) : $h;
            if (!$hydrator instanceof HydratorInterface) {
                throw new InvalidArgumentException("Hydrator for $entityClass must implement HydratorInterface");
            }
            // Кэшируем обратно экземпляр, чтобы не вызывать фабрику повторно
            return $this->hydrators[$entityClass] = $hydrator;
        }

        if (!is_a($entityClass, DataEntityInterface::class, true)) {
            throw new InvalidArgumentException("$entityClass must implement ".DataEntityInterface::class);
        }

        /** @var class-string<DataEntity> $entityClass */
        $fieldsMap = $entityClass::getFieldsMap();
        $dtoClass  = \method_exists($entityClass, 'getDtoClass') ? $entityClass::getDtoClass() : null;

        $hydrator = new MapHydrator($fieldsMap, $dtoClass);

        // Кэш
        return $this->hydrators[$entityClass] = $hydrator;
    }

    public function has(string $entityClass): bool
    {
        return isset($this->managers[$entityClass]);
    }

    /**
     * Получить экземпляр DataManager-а
     * @template T of DataManager
     * @param class-string<T> $className
     * @return T
     */
    public function get(string $className): DataManager
    {
        if (!isset($this->managers[$className])) {
            $hydrator = $this->getHydrator($className);
            $this->managers[$className] = new $className($this->db, $this->f4, $hydrator, $this->cache);
        }

        return $this->managers[$className];
    }



    /**
     * Получить все зарегистрированные DataManager-ы
     * @return array<string, DataManager>
     */
    public function all(): array
    {
        return $this->managers;
    }
}

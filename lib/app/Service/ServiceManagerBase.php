<?php

namespace App\Service;

use App\Service\DataManager;
use App\Service\DataManagerRegistry;
use App\F4;

/**
 * Базовый класс для всех сервисов, которым нужны DataManager'ы.
 */
abstract class ServiceManagerBase
{
    protected DataManagerRegistry $registry;
    protected F4 $f4;

    public function __construct(DataManagerRegistry $registry, F4 $f4)
    {
        $this->registry = $registry;
        $this->f4 = $f4;
        $this->initDataManagers();
    }

    /**
     * Универсальный доступ к любому DataManager'у.
     * Пример: $this->dm(BaseData::class)->getById($id)
     */
    public function dm(string $entityClass): DataManager
    {
        return $this->registry->get($entityClass);
    }

    /**
     * Прямой вызов метода менеджера:
     *   $this->call(BaseData::class, 'getById', $id)
     */
    public function call(string $entityClass, string $method, ...$args): mixed
    {
        $manager = $this->dm($entityClass);
        if (!method_exists($manager, $method)) {
            throw new \BadMethodCallException(sprintf(
                'Method %s::%s not found', $entityClass, $method
            ));
        }
        return $manager->$method(...$args);
    }

    /**
     * Автоинициализация полей из константы DATA_MANAGERS.
     */
    private function initDataManagers(): void
    {
        $const = (new \ReflectionClass(static::class))->getConstants();
        $map   = $const['DATA_MANAGERS'] ?? [];

        if (!is_array($map)) return;

        foreach ($map as $prop => $className) {
            // Заполняем только если свойство действительно существует
            if (property_exists($this, $prop)) {
                /** @var DataManager $mgr */
                $mgr = $this->registry->get($className);
                $this->$prop = $mgr;
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Base;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

final class ServiceLocator
{
    /** Текущий контейнер; null до явной сборки */
    private ?ContainerInterface $container = null;

    /** Накопленные определения (массив/файл/DefinitionSource) */
    private array $definitions = [];

    /** Необязательный внешний билдер (например, настроенный в тестах) */
    private ?ContainerBuilder $builder = null;

    /** Флаги сборки */
    private bool $autowiring = true;
    private ?string $compiledDir = null;

    public function __construct()
    {
    }

    // ---------- Конфигурация перед сборкой ----------

    public function addDefinitions(mixed $definitions): self
    {
        $this->definitions[] = $definitions;
        return $this;
    }

    public function clearDefinitions(): self
    {
        $this->definitions = [];
        return $this;
    }

    public function useAutowiring(bool $on): self
    {
        $this->autowiring = $on;
        return $this;
    }

    public function enableCompilation(?string $dir): self
    {
        $this->compiledDir = $dir;
        return $this;
    }

    public function withBuilder(ContainerBuilder $builder): self
    {
        $this->builder = $builder;
        return $this;
    }

    // ---------- Жизненный цикл контейнера ----------

    /** ЯВНАЯ сборка контейнера (единственная «магия» здесь) */
    public function initContainer(?ContainerBuilder $builder = null): self
    {
        $builder ??= ($this->builder ?? new ContainerBuilder());
        $builder->useAutowiring($this->autowiring);

        if ($this->compiledDir) {
            $builder->enableCompilation($this->compiledDir);
        }
        foreach ($this->definitions as $defs) {
            $builder->addDefinitions($defs);
        }

        $this->container = $builder->build();
        $this->builder   = $builder; // сохраняем для возможного rebuild()
        return $this;
    }

    /** Пересборка на текущем наборе настроек и определений */
    public function rebuild(): self
    {
        $this->container = null;
        return $this->initContainer($this->builder);
    }

    /** Принудительно подменить контейнер (удобно в тестах) */
    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        return $this;
    }

    public function isInitialized(): bool
    {
        return $this->container !== null;
    }

    public function getContainer(): ContainerInterface
    {
        if (!$this->container) {
            throw new \RuntimeException('DI container is not initialized');
        }
        return $this->container;
    }

    // ---------- Доступ к сервисам ----------

    public function get(string $id): mixed
    {
        if (!$this->container) {
            throw new \RuntimeException('DI container is not initialized');
        }
        return $this->container->get($id);
    }

    /** Если контейнер не собран — строго false (без ленивой магии) */
    public function has(string $id): bool
    {
        if (!$this->container) {
            return false;
        }
        return $this->container->has($id);
    }

    /**
     * Строгая проверка наличия только по явным определениям.
     * Собирает временный контейнер БЕЗ автосвязывания.
     * Использовать точечно — операция дорогая.
     */
    public function hasStrict(string $id): bool
    {
        $tmp = new ContainerBuilder();
        $tmp->useAutowiring(false);
        foreach ($this->definitions as $defs) {
            $tmp->addDefinitions($defs);
        }
        $c = $tmp->build();
        return $c->has($id);
    }

    // ---------- Вспомогательное ----------

    public function getDefinitions(): array
    {
        return $this->definitions;
    }
}

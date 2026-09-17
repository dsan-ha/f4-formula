<?php

declare(strict_types=1);

namespace App\Base;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Infrastructure boundary over PHP-DI.
 *
 * IMPORTANT:
 * - get()/has() are allowed only in composition roots and infrastructure
 *   dispatchers/registries that resolve a runtime class-string.
 * - business services, controllers, middleware and components must use
 *   constructor injection and must not receive ServiceLocator just to fetch
 *   ordinary dependencies.
 * - make() is a runtime-object factory: constructors receive only typed DI
 *   dependencies; framework/runtime state is applied afterwards through
 *   RuntimeFactoryInterface::setFactoryContext().
 */
final class ServiceLocator
{
    /** Current container; null until explicit build. */
    private ?ContainerInterface $container = null;

    /** Accumulated PHP-DI definitions. */
    private array $definitions = [];

    /** Optional externally configured builder (primarily tests/bootstrap). */
    private ?ContainerBuilder $builder = null;

    private bool $autowiring = true;
    private ?string $compiledDir = null;

    // ---------- Configuration before build ----------

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

    // ---------- Container lifecycle ----------

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
        $this->builder = $builder;

        return $this;
    }

    public function rebuild(): self
    {
        $this->container = null;
        return $this->initContainer($this->builder);
    }

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
        return $this->requireContainer();
    }

    // ---------- Infrastructure service access ----------

    /**
     * Resolve a shared service from PHP-DI.
     *
     * Do not use this as an application-level replacement for F4::getDI().
     * Ordinary classes must receive dependencies through their constructor.
     */
    public function get(string $id): mixed
    {
        return $this->requireContainer()->get($id);
    }

    /**
     * Runtime factory for infrastructure registries/factories.
     *
     * Constructor arguments are class-specific DI dependencies only and are
     * resolved from the already built PHP-DI container. Runtime/framework
     * state is never threaded through constructors. If context is supplied,
     * the created object must implement RuntimeFactoryInterface and receives
     * it through setFactoryContext().
     *
     * The method always creates a NEW instance and never registers it as a
     * shared container entry.
     */
    public function make(string|object $target, array $context = []): object
    {
        $container = $this->requireContainer();

        if (is_object($target)) {
            $instance = $target;
            $id = $target::class;
        } else {
            $id = $target;

            if (!class_exists($id)) {
                throw new \InvalidArgumentException("Runtime class does not exist: {$id}");
            }

            $reflection = new ReflectionClass($id);
            if (!$reflection->isInstantiable()) {
                throw new \RuntimeException("Runtime class is not instantiable: {$id}");
            }

            $constructor = $reflection->getConstructor();
            $arguments = [];

            if ($constructor !== null) {
                foreach ($constructor->getParameters() as $parameter) {
                    $dependencyId = $this->classDependencyId($parameter->getType());
                    if ($dependencyId !== null) {
                        try {
                            $arguments[] = $container->get($dependencyId);
                            continue;
                        } catch (NotFoundExceptionInterface) {
                            // Optional/default constructor parameters may still be valid.
                        }
                    }

                    if ($parameter->isDefaultValueAvailable()) {
                        $arguments[] = $parameter->getDefaultValue();
                        continue;
                    }

                    if ($parameter->allowsNull()) {
                        $arguments[] = null;
                        continue;
                    }

                    throw new \RuntimeException($this->unresolvedParameterMessage($id, $parameter));
                }
            }

            $instance = $reflection->newInstanceArgs($arguments);
        }

        if ($context !== []) {
            if ($instance instanceof RuntimeFactoryInterface) {
                $instance->setFactoryContext($context);
                return $instance;
            }

            // A pre-built object may be a legacy/self-contained factory result
            // (for example an anonymous InstallerInterface returned directly
            // from install/index.php). ServiceLocator did not construct it, so
            // there is no constructor/runtime-context contract to enforce here.
            if (is_object($target)) {
                return $instance;
            }

            // For class-string targets ServiceLocator owns object creation. In
            // that case silently discarding factory context would hide a broken
            // runtime-factory class, so fail fast.
            throw new \RuntimeException(sprintf(
                '%s was created by ServiceLocator::make() with runtime factory context ' .
                'but does not implement %s. Runtime-created Component, Installer and ' .
                'DataManager classes must inherit/implement the runtime factory contract. ' .
                'Do not move factory context into the constructor.',
                $id,
                RuntimeFactoryInterface::class
            ));
        }

        return $instance;
    }

    /**
     * Infrastructure-only optional dependency probe.
     * If the container is not built yet, returns false without lazy bootstrap.
     */
    public function has(string $id): bool
    {
        return $this->container?->has($id) ?? false;
    }

    /**
     * Checks only explicit definitions by building a temporary container with
     * autowiring disabled. This is intentionally expensive and should remain
     * a diagnostic/infrastructure tool.
     */
    public function hasStrict(string $id): bool
    {
        $tmp = new ContainerBuilder();
        $tmp->useAutowiring(false);

        foreach ($this->definitions as $defs) {
            $tmp->addDefinitions($defs);
        }

        return $tmp->build()->has($id);
    }

    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    // ---------- Internal helpers ----------

    private function requireContainer(): ContainerInterface
    {
        if ($this->container === null) {
            throw new \RuntimeException(
                'DI container is not initialized. Build Kernel/PHP-DI before resolving services.'
            );
        }

        return $this->container;
    }

    /**
     * Returns one unambiguous class/interface dependency from a parameter type.
     * Builtins are runtime values and therefore are never resolved from DI.
     */
    private function classDependencyId(?ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? null : $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            $classTypes = [];

            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType && !$unionType->isBuiltin()) {
                    $classTypes[] = $unionType->getName();
                }
            }

            return count($classTypes) === 1 ? $classTypes[0] : null;
        }

        // Intersection types require an explicit definition/runtime argument;
        // guessing which implementation to resolve would reintroduce magic.
        if ($type instanceof ReflectionIntersectionType) {
            return null;
        }

        return null;
    }

    private function unresolvedParameterMessage(string $id, ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        $typeText = $type ? (string)$type : 'untyped';

        return sprintf(
            'Cannot resolve constructor parameter $%s (%s) for %s::__construct(). ' .
            'Runtime factory constructors may contain only class-specific DI dependencies. ' .
            'Register/type-hint a resolvable DI dependency, provide a default/null value, ' .
            'or remove factory-context parameters from the constructor.',
            $parameter->getName(),
            $typeText,
            $id
        );
    }
}

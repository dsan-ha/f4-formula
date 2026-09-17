<?php
declare(strict_types=1);

namespace App\Base;

/**
 * Marker contract for objects created by infrastructure runtime factories.
 *
 * Constructor = class-specific DI dependencies only.
 * setFactoryContext() = framework/runtime state supplied by the owning factory.
 */
interface RuntimeFactoryInterface
{
    /** @param array<string,mixed> $context */
    public function setFactoryContext(array $context): void;
}

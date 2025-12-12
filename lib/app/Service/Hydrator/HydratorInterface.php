<?php

namespace App\Service\Hydrator;

interface HydratorInterface {
    /** @return object|array DTO или массив — на твой выбор */
    public function fromArray(array $row): object|array;
    public function toArray(object|array $dto): array;
}
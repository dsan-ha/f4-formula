<?php

namespace App\Service\Hydrator;

use App\Service\Hydrator\HydratorInterface;

final class MapHydrator implements HydratorInterface
{
    public function __construct(
        /** @var array<string,array> $map */
        private array $map,
        /** Класс DTO или null — если null, вернём ассоц-массив */
        private ?string $dtoClass = null
    ) {}

    public function fromArray(array $row): object|array {
        $out = [];
        foreach ($row as $k=>$v) {
            $type = $this->map[$k]['type'] ?? null;
            $out[$k] = $this->castFromDb($v, $type);
        }
        if ($this->dtoClass) {
            return new ($this->dtoClass)($out); // DTO с public readonly/typed props
        }
        return $out;
    }

    public function toArray(object|array $dto): array {
        $arr = is_array($dto) ? $dto : get_object_vars($dto);
        $out = [];
        foreach ($arr as $k=>$v) {
            $type = $this->map[$k]['type'] ?? null;
            $out[$k] = $this->castToDb($v, $type);
        }
        return $out;
    }

    private function castFromDb(mixed $v, ?string $type): mixed {
        if ($v === null) return null;
        return match ($type) {
            'int'    => (int)$v,
            'float'  => (float)$v,
            'bool'   => (bool)$v,
            'json'   => is_string($v) ? json_decode($v, true) : $v,
            'string' => (string)$v,
            default  => $v,
        };
    }

    private function castToDb(mixed $v, ?string $type): mixed {
        if ($v === null) return null;
        return match ($type) {
            'json'   => is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE),
            'int'    => (int)$v,
            'float'  => (float)$v,
            'bool'   => (int)(bool)$v,
            'string' => (string)$v,
            default  => $v,
        };
    }
}

<?php
namespace App\Service\Hydrator;

/**
 * Базовый DTO для всех сущностей.
 * Можно расширять и добавлять доменные методы.
 */
abstract class DtoBase
{
    /**
     * Конструктор принимает ассоциативный массив и мапит его в свойства.
     */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            // Назначаем только существующие свойства
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Заполняет объект массивом данных (альтернатива конструктору).
     */
    public function fill(array $data): static
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        return $this;
    }

    /**
     * Конвертация в массив (например, для API-ответа).
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Магия для отладки.
     */
    public function __toString(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

<?php
// src/App/Events/EventResult.php
namespace App\Events;

final class EventResult
{
    public const SUCCESS   = 1;
    public const ERROR     = 0;
    public const UNDEFINED = -1;

    public function __construct(
        private int $resultType,
        private mixed $parameters = null,
        private ?string $message = null,
        private ?string $handlerId = null,
    ) {}

    public static function success(mixed $parameters=null, ?string $message=null): self {
        return new self(self::SUCCESS, $parameters, $message);
    }
    public static function error(mixed $parameters=null, ?string $message=null): self {
        return new self(self::ERROR, $parameters, $message);
    }
    public static function undefined(mixed $parameters=null, ?string $message=null): self {
        return new self(self::UNDEFINED, $parameters, $message);
    }

    public function getResultType(): int      { return $this->resultType; }
    public function getParameters(): mixed    { return $this->parameters; }
    public function getMessage(): ?string     { return $this->message; }
    public function getHandlerId(): ?string   { return $this->handlerId; }
    public function withHandlerId(string $id): self {
        $clone = clone $this; $clone->handlerId = $id; return $clone;
    }
}

<?php
namespace App\Http\I;

interface ResponseInterface
{
    public function getStatus(): int;
    public function withStatus(int $code): self;
    public function getBody(): string;
    public function withBody(string $body): self;
    public function getHeader(string $name): array;
    public function withHeader(string $name, string|array $value, bool $replace = true): self;
    public function hasHeader(string $name): bool;
    public function send($cli = false): void;
    public function isSent(): bool;
}
<?php

namespace App\Http;

class Response
{
    protected int $status = 200;
    protected array $headers = [];
    protected array $headerIndex = [];
    protected string $body = '';
    protected bool $sent = false;

    // ==== Управление статусом ====
    public function getStatus(): int { return $this->status; }
    public function withStatus(int $code): self {
        $clone = clone $this;
        $clone->status = $code;
        return $clone;
    }

    public function hasHeader(string $name): bool {
        return isset($this->headerIndex[strtolower($name)]);
    }
    public function getHeader(string $name): array {
        $lc = strtolower($name);
        if (!isset($this->headerIndex[$lc])) return [];
        $orig = $this->headerIndex[$lc];
        return $this->headers[$orig];
    }

    // ---- PSR-7 getHeaderLine ----
    public function getHeaderLine(string $name): string {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, string|array $value, bool $replace = true): self {
        $clone = clone $this;
        $lc = strtolower($name);
        $vals = is_array($value) ? array_values($value) : [$value];

        if ($replace || !isset($clone->headerIndex[$lc])) {
            $clone->headerIndex[$lc] = $name;     // запоминаем оригинальный кейс
            $clone->headers[$name] = $vals;
        } else {
            $orig = $clone->headerIndex[$lc];
            $clone->headers[$orig] = array_merge($clone->headers[$orig], $vals);
        }
        return $clone;
    }

    // ==== Управление телом ответа ====
    public function getBody(): string { return $this->body; }
    public function withBody(string $body): self {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }
    public function write(string $chunk): self {
        $clone = clone $this;
        $clone->body .= $chunk;
        return $clone;
    }

    // ==== Отправка ====
    public function isSent(): bool { return $this->sent; }
    public function send($cli = false): void {
        if ($this->sent) return;
        http_response_code($this->status);
        if(!$cli){
            foreach ($this->headers as $name => $values) {
                foreach ($values as $value) {
                    header($name . ': ' . $value, false);
                }
            }
        }
        echo $this->body;
        $this->sent = true;
    }
}

<?php

namespace App\Http;

class Response
{
    protected int $status = 200;
    protected array $headers = [];
    protected array $headerIndex = [];
    protected string $body = '';
    protected bool $sent = false;
    protected array $data = [];
    protected array $errors = [];
    protected string $flag = 'ok'; // ok|error

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

    public function withData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function getData(): array
    {
        return $this->data;
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

    public function addError(array $error): self
    {
        $this->flag = 'error';

        $cnt = count($error);

        // 1 элемент: просто сообщение
        if ($cnt === 1) {
            $message = (string)array_values($error)[0];
            $this->errors[] = $message;
            return $this;
        }

        // 2 элемента: field => message
        if ($cnt === 2) {
            $vals = array_values($error);
            $field = (string)$vals[0];
            $message = (string)$vals[1];

            if ($field === '') {
                // если поле пустое, считаем безликой
                $this->errors[] = $message;
            } else {
                // хранение как map field=>[messages...]
                if (!isset($this->errors[$field])) $this->errors[$field] = [];
                $this->errors[$field][] = $message;
            }

            return $this;
        }

        throw new \InvalidArgumentException('addError expects [message] or [field, message]');
    }

    public function makeBody(): array
    {
        return [
            'flag'   => $this->flag,
            'data'   => $this->data,
            'errors' => $this->errors,
        ];
    }
    
    public function ok(array $data = []): self
    {
        $this->flag = 'ok';
        $this->errors = [];
        $this->data = $data;
        return $this;
    }

    public function error(string $message = '', ?string $field = null): self
    {
        $this->flag = 'error';
        if ($message !== '') {
            $field ? $this->addError([$field, $message]) : $this->addError([$message]);
        }
        return $this;
    }
}

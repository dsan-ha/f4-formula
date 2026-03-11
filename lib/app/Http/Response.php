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
        $body = [
            'flag'   => $this->flag,
            'data'   => $this->data,
            'errors' => $this->errors,
        ];
        if(!empty($this->errors)) $body['error'] = $this->errors[array_keys($this->errors)[0]];
        return $body;
    }
    
    public function ok(array $data = []): self
    {
        $this->flag = 'ok';
        $this->errors = [];
        $this->data = $data;
        return $this;
    }

    public function error(string|array|\Throwable $message = '', array $data = []): self
    {
        // если вызвали просто ->error() без текста
        $this->flag = 'error';
        $this->data = $data;

        if ($message instanceof \Throwable) {
            $message = $message->getMessage();
        }

        // строка
        if (is_string($message)) {
            $msg = trim($message);
            if ($msg !== '') {
                $this->addError([$msg]);
            }
            return $this;
        }

        // массив
        if (is_array($message)) {
            if ($message === []) {
                return $this;
            }

            // список ошибок: [[...], [...]]
            $isListOfErrors = isset($message[0]) && is_array($message[0]);
            if ($isListOfErrors) {
                foreach ($message as $err) {
                    if (!is_array($err)) continue;

                    // [field, message] или [message]
                    $cnt = count($err);
                    if ($cnt === 1) {
                        $this->addError([(string)array_values($err)[0]]);
                    } elseif ($cnt === 2) {
                        $vals = array_values($err);
                        $this->addError([(string)$vals[0], (string)$vals[1]]);
                    }
                }
                return $this;
            }

            // форма ['field'=>..., 'message'=>...]
            if (isset($message['message'])) {
                $field = (string)($message['field'] ?? '');
                $msg   = (string)$message['message'];
                $this->addError($field !== '' ? [$field, $msg] : [$msg]);
                return $this;
            }

            // форма [field, message] или [message]
            $cnt = count($message);
            if ($cnt === 1) {
                $this->addError([(string)array_values($message)[0]]);
            } elseif ($cnt === 2) {
                $vals = array_values($message);
                $this->addError([(string)$vals[0], (string)$vals[1]]);
            } else {
                $this->addError(['Неизвестная ошибка']);
            }

            return $this;
        }

        // всё остальное
        $this->addError(['Неизвестная ошибка']);
        return $this;
    }


    public function fromResult(\App\Http\Result $r): self
    {
        if ($r->flag === 'ok') {
            return $this->ok($r->data)->withStatus($r->status);
        }
        $this->data = $r->data;
        if(!empty($r->errors[0]['message'])){
            foreach ($r->errors as $e) {
                $arr = !empty($e['field'])?[$e['field'],$e['message']]:[$e['message']];
                $this->addError($arr);
            }
        } else {
            $this->error('Ошибка');
        }
        return $this->withStatus($r->status);
    }
    
    /**
    *   json answer
    *   @return Response
    **/
    public function json(Response $res): Response
    {
        $bodyArray = $res->makeBody();
        return $res->withHeader('Content-Type','application/json; charset=utf-8')->withBody(json_encode($bodyArray, JSON_UNESCAPED_UNICODE ));
    }
}

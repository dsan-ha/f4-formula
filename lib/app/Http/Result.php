<?php
namespace App\Http;

final class Result
{
    public string $flag = 'ok';     // ok|error
    public array $data = [];
    public array $errors = [];      // [{field, message}] или key=>msg, как решишь
    public int $status = 200;

    public static function ok(array $data = [], int $status = 200): self
    {
        $r = new self();
        $r->flag = 'ok';
        $r->data = $data;
        $r->status = $status;
        return $r;
    }

    public static function error(string $message, ?string $field = null, int $status = 400, array $data = []): self
    {
        $r = new self();
        $r->flag = 'error';
        $r->status = $status;
        $r->data = $data;
        $r->addError($message, $field);
        return $r;
    }

    public function addError(string $message, ?string $field = null): self
    {
        $this->errors[] = $field ? ['field' => $field, 'message' => $message] : ['message' => $message];
        $this->flag = 'error';
        if ($this->status < 400) $this->status = 400;
        return $this;
    }

    public function with(array $data): self
    {
        $this->data = array_replace_recursive($this->data, $data);
        return $this;
    }

    public function isOk(): bool
    {
        return $this->flag === 'ok';
    }
}

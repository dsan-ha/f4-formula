<?php

namespace App\Http;

class Request
{
    /** @var array */
    protected array $server;
    protected string $clientIp;
    protected array $query;
    protected string $query_str;
    protected array $post;
    protected array $request_params;
    protected array $cookies;
    protected array $files;
    protected array $headers;
    protected mixed $body;
    protected string $method;
    protected string $uri;
    protected string $path;
    protected int $port;
    protected string $scheme;
    protected string $host;
    protected bool $cli;
    protected bool $ajax;
    protected array $attributes = []; // route params, auth user, etc.

    public function __construct(
        string $method,
        string $scheme,
        string $host,
        int    $port,
        string $path,
        string $query_str,
        array  $headers,  // lowercased
        array  $server,   // snapshot
        mixed $body,
        string $clientIp,
        bool    $cli = false
    )
    {
        $this->cli = $cli;
        $this->server = $server;
        $this->query  = $_GET ?? [];
        $this->post  = $_POST ?? [];
        $this->files  = $_FILES ?? [];
        $this->request_params  = $_REQUEST ?? [];
        $this->cookies = $_COOKIE ?? [];
        $this->headers =  $headers;
        $this->ajax = (isset($this->headers['X-Requested-With']) && 
               $this->headers['X-Requested-With'] == 'XMLHttpRequest') ||
              (isset($server['HTTP_X_REQUESTED_WITH']) && 
               $server['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest');
        $this->body    = $body;
        $this->method = $method;
        $this->port = $port;
        $this->scheme = $scheme;
        $this->host   = $host;
        $this->path   = $path;
        $this->query_str = $query_str;
        $auth = $this->host . (($this->scheme === 'https' && $this->port===443) || ($this->scheme !== 'https' && $this->port===80) ? '' : ':'.$this->port);
        $this->uri    = $this->server['REQUEST_URI'] ?? $this->scheme.'://'.$auth.$this->path.($this->query_str ? '?'.$this->query_str : '');
        $this->clientIp = $clientIp;
    }

    // ==== Геттеры данных ====
    public function get(string $key = '', $default=null) { 
        return $key?($this->request_params[$key] ?? $default):$this->request_params;
    }
    public function post(string $key = '', $default=null) { 
        return $key?($this->post[$key] ?? $default):$this->post; 
    }
    public function query(string $key = '', $default=null) { 
        return $key?($this->query[$key] ?? $default):$this->query; 
    }
    public function files(string $key='') { 
        return $key?($this->files[$key]??null):$this->files; 
    }
    public function body() {
        return $this->body;
    }
    public function isCli(): bool { return $this->cli; }
    public function isAjax(): bool { return $this->ajax; }
    public function getServer(): array { return $this->server; }
    public function getQueryStr(): ?string { return $this->query_str; }
    public function getRequestParams(): array { return $this->request_params; }
    public function getCookies(): array { return $this->cookies; }
    public function clientIp(): string { return $this->clientIp; }
    public function getPort(): string { return $this->port; }
    public function getHeaders(): array { return $this->headers; }
    public function getHeader(string $name, $default=null) {
        $name = strtolower($name);
        foreach ($this->headers as $key => $val) {
            if (strtolower($key) === $name) {
                return $val;
            }
        }
        return $default;
    }
    public function getBody(): ?string { return $this->body; }
    public function getMethod(): string { return $this->method; }
    public function getUri(): string { return $this->uri; }
    public function getPath(): string { return $this->path; }
    public function getScheme(): string { return $this->scheme; }
    public function getHost(): string { return $this->host; }

    // ==== Атрибуты для передачи данных по цепочке ====
    public function getAttribute(string $key, $default = null) {
        return $this->attributes[$key] ?? $default;
    }
    public function withAttribute(string $key, $value): self {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }
}

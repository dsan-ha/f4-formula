<?php

namespace App\Http;

class Route{
    private array $params;
    public MiddlewareDispatcher $middleware;

    public function __construct(array $params) {
        $this->params = $params;
        $this->middleware = new MiddlewareDispatcher();
    }


    public function addMiddleware(callable $mw): self { $this->middleware->add($mw); return $this; }


    public function getParams(): array { return $this->params; }


    public static function joinPath(string $a, string $b): string {
        if ($a === '') return $b;
        $a = '/' . trim($a, '/');
        $b = '/' . ltrim($b, '/');
        $path = rtrim(preg_replace('#/+#','/', $a . $b), '/') ?: '/';
        return $path;
    }
}
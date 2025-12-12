<?php

namespace App\Http;

class RoutesCollection{
    private $routes;

    public function addRoute(&$route) {
        $this->routes[] = $route;
    }

    public function add(callable $mw) {
        foreach ($this->routes as &$route) {
            $route->addMiddleware($mw);
        }
        return $this;
    }

    /** @return Route[] */
    public function all(): array { return $this->routes; }
}
<?

namespace App\Http;

class MiddlewareDispatcher {
    private $stack = [];

    public function add(callable $middleware) {
        $this->stack[] = $middleware;
        return $this;
    }

    public function dispatch($req, $res, array $params, callable $finalHandler) {
        $stack = array_reverse($this->stack);
        $next = $finalHandler;

        foreach ($stack as $middleware) {
            $next = function ($reqx, $resx, $paramsx) use ($middleware, $next) {
                return $middleware($reqx, $resx, $paramsx, $next);
            };
        }

        return $next($req, $res, $params);
    }

    public function all(){
        return $this->stack;
    }
}
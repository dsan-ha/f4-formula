<?

namespace App\Http;

use ReflectionFunction;
use ReflectionMethod;
use App\Http\MiddlewareState;
use App\Http\Request;
use App\Http\Response;

class MiddlewareDispatcher
{
    /**
     * @var array<int, array{0:mixed, 1:MiddlewareState}>
     */
    private $stack = [];

    /**
     * @param mixed $mw callable|string|array|object
     */
    public function add($mw, MiddlewareState $state = null)
    {
        if ($state === null) {
            $state = MiddlewareState::main();
        }

        $this->stack[] = [$mw, $state];
        return $this;
    }

    /**
     * @return array
     */
    public function all()
    {
        return $this->stack;
    }

    /**
     * @param callable $final function($req,$res,array $args): Response
     */
    public function dispatch($req, $res, array $args, $final)
    {
        $self = $this;

        $runner = function ($req, $res, array $args) use ($final, $self) {
            // финальный обработчик (контроллер/хендлер)
            return $self->invoke($final, $req, $res, $args, function ($req, $res, array $args) {
                return $res;
            });
        };

        $rev = array_reverse($this->stack);

        foreach ($rev as $pair) {
            $mw = $pair[0];
            $next = $runner;

            $runner = function ($req, $res, array $args) use ($mw, $next, $self) {
                return $self->invoke($mw, $req, $res, $args, $next);
            };
        }

        return $runner($req, $res, $args);
    }

    /**
     * Унифицированный вызов middleware/handler
     *
     * @param mixed $mw callable|object|string|array
     * @return Response
     */
    private function invoke($mw, $req, $res, array $args, callable $next)
    {
        // 1) Объект middleware
        if (is_object($mw)) {
            // Если есть интерфейс - хорошо. Если нет - достаточно __invoke()
            if ($mw instanceof MiddlewareInterface || method_exists($mw, '__invoke')) {
                $out = $mw($req, $res, $args, $next);
            } elseif (method_exists($mw, 'handle')) {
                $out = $mw->handle($req, $res, $args, $next);
            } else {
                throw new \InvalidArgumentException('Middleware object must be invokable (__invoke) or have handle()');
            }
        } else {
            // 2) Любой callable (строка, массив, замыкание)
            if (!is_callable($mw)) {
                throw new \InvalidArgumentException('Middleware must be callable or invokable object');
            }

            // ВАЖНО: main-chain всегда 4 аргумента
            $out = call_user_func($mw, $req, $res, $args, $next);
        }

        if (!($out instanceof Response)) {
            user_error('Middleware must return Response', E_USER_ERROR);
        }

        return $out;
    }

    public function __clone()
    {
        $this->stack = array_values($this->stack);
    }
}
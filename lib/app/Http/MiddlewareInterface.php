<?

namespace App\Http;

interface MiddlewareInterface
{
    public function __invoke($req, $res, array $args, callable $next);
}

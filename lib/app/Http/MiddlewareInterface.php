<?

namespace App\Http;

use App\Http\Request;
use App\Http\Response;

interface MiddlewareInterface
{
    public function __invoke(Request $req, Response $res, array $args, callable $next): Response;
}

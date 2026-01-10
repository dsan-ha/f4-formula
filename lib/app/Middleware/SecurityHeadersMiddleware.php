<?php

namespace App\Middleware;

use App\F4;
use App\Http\Request;
use App\Http\Response;

/**
 * Системные заголовки безопасности.
 */
final class SecurityHeadersMiddleware
{
    public function __invoke(Request $req, Response $res, array $params, callable $next): Response
    {
        $res = $next($req, $res, $params);

        if ($req->isCli()) {
            return $res;
        }

        if (!$res->hasHeader('X-Frame-Options')) {
            $res = $res->withHeader('X-Frame-Options', 'SAMEORIGIN');
        }
        if (!$res->hasHeader('X-Content-Type-Options')) {
            $res = $res->withHeader('X-Content-Type-Options', 'nosniff');
        }
        if (!$res->hasHeader('X-XSS-Protection')) {
            // Заголовок устарел, но оставляем для обратной совместимости
            $res = $res->withHeader('X-XSS-Protection', '1; mode=block');
        }

        return $res;
    }
}
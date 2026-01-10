<?php

namespace App\Middleware;

use App\F4;
use App\Http\Request;
use App\Http\Response;

/**
 * Заголовки кеширования.
 */
final class CacheHeadersMiddleware
{
    public function __invoke(Request $req, Response $res, array $params, callable $next): Response
    {
        $res = $next($req, $res, $params);

        if ($req->isCli()) {
            return $res;
        }

        $f4 = F4::instance();
        $ttl = (int)$f4->get('ROUTE_TTL');
        $now = time();

        $method = $req->getMethod();
        $cacheable = ($ttl > 0) && ($method === 'GET' || $method === 'HEAD');

        if ($cacheable) {
            // Убираем Pragma если есть поддержка withoutHeader()
            if (method_exists($res, 'withoutHeader')) {
                $res = $res->withoutHeader('Pragma');
            }

            $res = $res
                ->withHeader('Cache-Control', 'max-age=' . $ttl)
                ->withHeader('Expires', gmdate('D, d M Y H:i:s', $now + $ttl) . ' GMT');

            if (!$res->hasHeader('Last-Modified')) {
                $res = $res->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $now) . ' GMT');
            }
        } else {
            $res = $res
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->withHeader('Expires', 'Fri, 01 Jan 1990 00:00:00 GMT');
        }

        return $res;
    }
}
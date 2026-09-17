<?php if(!defined('SITE_ROOT')) exit();

use App\Base\Kernel;
use App\Http\MiddlewareState;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\CacheHeadersMiddleware;

$kernel = Kernel::instance();
$f4 = $kernel->f4();

// CSRF защита (проверка токена)
//$f4->add($kernel->get(CsrfMiddleware::class), MiddlewareState::before(500));

// Безопасность заголовков
$f4->add($kernel->get(SecurityHeadersMiddleware::class), MiddlewareState::after(9000));
// Кэширование заголовков
$f4->add($kernel->get(CacheHeadersMiddleware::class), MiddlewareState::after(9001));

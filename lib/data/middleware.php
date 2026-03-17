<?php if(!defined('SITE_ROOT')) exit();
use App\Http\MiddlewareState;

$f4 = App\F4::instance();

// CSRF защита (проверка токена) 
//$f4->add($f4->getDI(\App\Middleware\CsrfMiddleware::class), MiddlewareState::before(500));

// Безопасность заголовков
$f4->add($f4->getDI(\App\Middleware\SecurityHeadersMiddleware::class), MiddlewareState::after(9000));
// Кэширование заголовков
$f4->add($f4->getDI(\App\Middleware\CacheHeadersMiddleware::class), MiddlewareState::after(9001));
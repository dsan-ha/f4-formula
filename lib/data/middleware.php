<?php if(!defined('SITE_ROOT')) exit();
use App\Http\MiddlewareState;

$f4 = App\F4::instance();

$f4->add(new \App\Middleware\SecurityHeadersMiddleware(), MiddlewareState::after(9000));
$f4->add(new \App\Middleware\CacheHeadersMiddleware(), MiddlewareState::after(9001));
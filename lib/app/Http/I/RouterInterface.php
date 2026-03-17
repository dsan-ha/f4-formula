<?php
namespace App\Http\I;

use App\Http\RouterGroups;

interface RouterInterface
{
    public function route($pattern, $handler, $ttl = 0, $kbps = 0);
    public function group($chainUrlGroup = ''): RouterGroups;
    public function addMiddleware($mw);
    public function run();
    public function reroute($url = NULL, $permanent = FALSE, $die = TRUE);
    public function redirect($pattern, $url, $permanent = TRUE);
}
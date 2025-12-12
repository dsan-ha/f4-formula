<?php if(!defined('SITE_ROOT')) exit();
use App\Service;
use App\F4;
use App\Base\SessionService;
use App\Base\CookieService;
use App\Events\EventManager;
use App\Http\Environment;
use App\Utils\Cache\FileCacheAdapter;
use App\Service\DataManagerRegistry;
use App\Component\ComponentManager;
use function DI\autowire;
use function DI\create;
use function DI\get;

$f4 = F4::instance();


$UIpaths = $f4->g('UI','ui/');

return [
    Environment::class => DI\factory(function () {
        return Environment::instance();
    }),
    F4::class => DI\factory(function () {
        $f4 = F4::instance();
        return $f4;
    }),
    SessionService::class => DI\create(SessionService::class),
    CookieService::class  => DI\create(CookieService::class),
    //route('route_str',$handler($request,$response,$params))
    App\Http\Response::class => create(App\Http\Response::class),
    App\Http\Request::class => DI\factory(fn() => Environment::instance()->getRequest()),
    //EventManager f3->get('EventManager')->addEventHandler()
    EventManager::class => create(EventManager::class)->constructor(get(F4::class)),

    App\Http\Router::class => create(App\Http\Router::class)->constructor(get(F4::class),get(App\Http\Request::class),get(App\Http\Response::class)),
    // App\Utils\Assets::instance()
    App\Utils\Assets::class => create(App\Utils\Assets::class),
    // f3_cache()
    App\Utils\Cache::class => DI\factory(function (F4 $f4) {
        $cache_folder = SITE_ROOT.ltrim($f4->get('cache.folder','lib/tmp/cache/'),'/');
        $adapter = new FileCacheAdapter($cache_folder);
        $cache = new App\Utils\Cache($adapter);
        return $cache;
    }),
    // component cache()
    App\View\CacheHelper::class => DI\factory(function (F4 $f4) {
        $cache_folder = SITE_ROOT.ltrim($f4->get('cache.folder','lib/tmp/cache/'),'/');
        $adapter = new FileCacheAdapter($cache_folder);
        $cache = new App\View\CacheHelper($adapter);
        return $cache;
    }),
    // template()->render()
    App\View\Template::class => create(App\View\Template::class)->constructor(get(F4::class),get(App\View\CacheHelper::class), $UIpaths),
    // app()
    App\App::class => create(App\App::class)->constructor(get(F4::class),get(App\Utils\Assets::class), get(ComponentManager::class))
];






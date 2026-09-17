<?php if(!defined('SITE_ROOT')) exit();
use App\Service;
use App\F4;
use App\Base\SessionService;
use App\Base\CookieService;
use App\Base\ServiceLocator;
use App\Base\Kernel;
use App\Events\EventManager;
use App\Http\Environment;
use App\Http\ErrorHandler;
use App\Utils\Cache\FileCacheAdapter;
use App\Service\DataManagerRegistry;
use App\Component\ComponentManager;    
use App\Middleware\CsrfMiddleware;
use App\Utils\Security\CsrfTokenManager;
use function DI\autowire;
use function DI\create;
use function DI\get;

$f4 = Kernel::instance()->f4();


$UIpaths = $f4->g('UI','ui/');

return [
    Environment::class => DI\factory(function () {
        return Environment::instance();
    }),
    SessionService::class => DI\create(SessionService::class),
    CookieService::class => DI\factory(function (F4 $f4) {
        $cookies = new CookieService();
        $jar = $f4->get('JAR');
        if (is_array($jar)) {
            $cookies->configure($jar);
        }
        return $cookies;
    }),
    //route('route_str',$handler($request,$response,$params))
    App\Http\Response::class => create(App\Http\Response::class),
    App\Http\Request::class => DI\factory(fn() => Environment::instance()->getRequest()),
    //EventManager f3->get('EventManager')->addEventHandler()
    EventManager::class => create(EventManager::class)->constructor(get(F4::class)),

    App\Http\Router::class => create(App\Http\Router::class)->constructor(get(F4::class), get(App\Http\Request::class), get(App\Http\Response::class), get(ServiceLocator::class),get(ErrorHandler::class)),
    // App\Utils\Assets::instance()
    App\Utils\Assets::class => create(App\Utils\Assets::class),
    App\Utils\Scheduler::class => create(App\Utils\Scheduler::class),
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
        $cache = new App\View\CacheHelper($adapter, $f4);
        return $cache;
    }),
    // template()->render()
    App\View\Template::class => create(App\View\Template::class)->constructor(get(F4::class),get(App\View\CacheHelper::class), $UIpaths),
    // app()
    App\App::class => create(App\App::class)->constructor(get(F4::class),get(App\Utils\Assets::class), get(ComponentManager::class)),
    // CSRF Protection
    CsrfMiddleware::class => create(CsrfMiddleware::class)
        ->constructor(get(SessionService::class)),
    CsrfTokenManager::class => create(CsrfTokenManager::class)
        ->constructor(get(SessionService::class)),
    App\Migrations\PhinxMigrator::class => DI\factory(fn(F4 $f4) => new App\Migrations\PhinxMigrator(null, null, $f4)),
];







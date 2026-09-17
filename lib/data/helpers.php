<?php if(!defined('SITE_ROOT')) exit();

use App\Base\Kernel;

/**
 * f3_cache - для вызова кэша
**/

function app(){
    return Kernel::instance()->get(\App\App::class);
}
function assets(){
    return Kernel::instance()->get(\App\Utils\Assets::class);
}
function template(){
    return Kernel::instance()->get(\App\View\Template::class);
}
function app_component(string $componentName, string $componentTemplate, array $arParams = []){
    return app()->component_manager->run($componentName, $componentTemplate, $arParams);
}
function ds(){
    return Kernel::instance()->get(\App\DS::class);
}
function f3_cache(){
    return Kernel::instance()->get(App\Utils\Cache::class); //Если не инициализирован кэш то выдаст ошибку
}


function dd($var, $pretty = true){
	
    $backtrace = debug_backtrace();
    echo "\n<pre>\n";
    if (isset($backtrace[0]['file'])) {
        echo $backtrace[0]['file'] . "\n\n";
    }
    echo "Type: " . gettype($var) . "\n";
    echo "Time: " . date('c') . "\n";
    echo "---------------------------------\n\n";
    ($pretty) ? print_r($var) : var_dump($var);
    echo "</pre>\n";
    die;
}

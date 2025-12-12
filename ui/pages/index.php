<?php if(!defined('SITE_ROOT')) exit();?>
Hello world! <br>
<hr>
<p>test cache</p>
<? $f4 = f4();
if(!$f4->cache_exists('rand_num',$rand_num)){
    $rand_num = rand();
    $f4->cache_set('rand_num',$rand_num,360);
}
echo 'rand: '.$rand_num;

echo app_component(
    'ds:test',
    'base',
    ['CACHE_TYPE' => 'Y','CACHE_TIME' => 360]
);?>
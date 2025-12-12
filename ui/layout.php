<?php if(!defined('SITE_ROOT')) exit();

use App\Utils\Assets;

$f4 = App\F4::instance();
$app = app();
$assets = assets();
$assets->addCss('/ui/css/d/normalize.min.css');
$assets->addCss('/ui/css/d/fontawesome.min.css');
$assets->addCss('/ui/css/d/pure.css');
$assets->addCss('/ui/css/d/base.css');
$assets->addCss('/ui/css/d/code.css');
$assets->addCss('/ui/css/style.css');
$assets->addJs('/ui/js/d/jquery-3.4.1.min.js');
$assets->addJs('/ui/js/main.js');?>
<!DOCTYPE html>
<html lang="ru">
	<head>
		<meta charset="<?=$f4->get('ENCODING'); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="color-scheme" content="light dark">
        <title><?=$app->getMeta('title','fff сайт')?></title>
		<? $app->showHead();?>
	</head>

  	<body>
        <?= $this->render($app->content('header'),$arParams);?>

        <!-- Main -->
        <main class="container">
        <?=$this->render($app->content('body'),$arParams); ?>
        </main>
        <?= $this->render($app->content('footer'),$arParams);?>       
        <? $app->showFooter();?>
	</body>
</html>

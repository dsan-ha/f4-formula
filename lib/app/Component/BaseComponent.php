<?php

namespace App\Component;

use App\F4;
use App\Utils\Assets;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

abstract class BaseComponent {
    protected F4 $f4;
    protected Assets $assets;
    protected string $folder;
    protected string $templateName;
    protected array $arParams = [];
    protected array $arResult = [];

    public function __construct(F4 $f4, Assets $assets, string $templateName, string $folder, array $arParams = [])
    {
        $this->f4 = $f4;
        $this->assets = $assets;
        $this->templateName = $templateName;
        $this->folder = $folder;
        $this->arParams = $arParams;
    }

    // Метод для переопределения: логика компонента
    abstract public function execute(): void;

    protected function includeStyleScript(): void {
        $assets = $this->assets;
        $stylePath = $this->getUIPath($this->folder.'style.css', true);
        $scriptPath = $this->getUIPath($this->folder.'script.js', true);
        if(!empty($stylePath))
            $assets->addCss($stylePath);
        if(!empty($scriptPath))
            $assets->addJs($scriptPath);
    }

    public function getDefaultParams($params_format = true): array
    {
        $blueprint = $this->getUIPath($this->folder . 'blueprint.yaml', false);
        $arParams = [];
        if (file_exists($blueprint)) {
            $raw = Yaml::parseFile($blueprint);
            if (is_array($raw)) {
                foreach ($raw as $key => $meta) {
                    if (is_array($meta) && array_key_exists('def', $meta)) {
                        $arParams[$key] = $params_format ? $meta['def'] : $meta;
                    }
                }
            }
        }
        
        if (method_exists($this,'prepareDefaults') && $params_format) {
            $arParams = $this->prepareDefaults($arParams);
        }

        if (!isset($arParams['CACHE_TYPE'])) $arParams['CACHE_TYPE'] = 'N'; // N|A|Y
        if (!isset($arParams['CACHE_TIME'])) $arParams['CACHE_TIME'] = 0;   // сек

        return $arParams;
    }

    // Метод рендеринга шаблона
    public function render(): string
    {
        $template = template();
        $arParams = $this->getDefaultParams();

        foreach ($this->arParams as $key => $val) {
            $arParams[$key] = $val;
        }
        $templatePath = $this->folder.'template.php';
        $template->set('arResult',$this->arResult);
        $template->set('templateFolder',$this->getUIPath($this->folder, true));
        $template->set('templateName',$this->templateName);
        $template->set('component',$this);

        $helper = $this->f4->getDI(\App\View\CacheHelper::class) ?? null;

        $renderer = function() use ($template, $templatePath, $arParams) {
            $this->runResultModifier($arParams);
            return $template->render($templatePath, $arParams);
        };
        $html = ($helper instanceof \App\View\CacheHelper)
            ? $helper->renderWithCache($this, $arParams, $renderer)
            : $renderer();
        $html .= $this->renderComponentEpilog($arParams);
        $this->includeStyleScript();
        return $html;
    }

    protected function runResultModifier(array $arParams): void
    {
        $modifierAbs = $this->getUIPath($this->folder . 'result_modifier.php', false);
        if (empty($modifierAbs) || !is_file($modifierAbs)) {
            return;
        }

        // переменные, которыми обычно пользуются в modifier
        $arResult = &$this->arResult;
        $component = $this;
        $assets = $this->assets;
        $templateFolder = $this->getUIPath($this->folder, true);
        $templateName = $this->templateName;

        // делаем scope под Bitrix: $this->__component
        $scope = new class($this) {
            public \App\Component\BaseComponent $__component;
            public function __construct(\App\Component\BaseComponent $component) { $this->__component = $component; }
        };

        // modifier не должен печатать ничего, поэтому глушим вывод
        ob_start();
        try {
            (function() use ($modifierAbs, &$arResult, $arParams, $component, $assets, $templateFolder, $templateName) {
                include $modifierAbs;
            })->call($scope);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        ob_end_clean();
    }


    protected function renderComponentEpilog(array $arParams): string
    {
        $epilogRel = $this->folder . 'component_epilog.php';
        $epilogAbs = $this->getUIPath($epilogRel, false);
        if (empty($epilogAbs) || !is_file($epilogAbs)) {
            return '';
        }

        // Переменные, к которым привыкли в Bitrix
        $component = $this;
        $arResult = &$this->arResult;
        $templateName = $this->templateName;
        $templateFolder = $this->getUIPath($this->folder, true);
        $assets = $this->assets;

        ob_start();
        try {
            include $epilogAbs;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    protected function getUIPath($path, bool $uri = false){
        $hive = $this->f4->get('UI');
        $roots = $hive ? explode(',', $hive) : [];

        foreach ($roots as $root) {
            $uriPath =  trim($root, '/\\') . '/' . ltrim($path, '/\\');
            $path = SITE_ROOT . $uriPath;
            if (is_file($path) || is_dir($path)) {
                return $uri?('/'.$uriPath):$path;
            }
        }
        return '';
    }

    public function getTemplateName(): string { return $this->templateName; }
    public function getTemplateFolder(): string { return $this->folder; }
}

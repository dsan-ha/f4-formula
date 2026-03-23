<?php

namespace App\Controller;

use App\F4;
use App\Http\Response;
use App\Utils\Security\CsrfTokenManager;

abstract class ControllerBase
{
    protected F4 $f4;
    protected CsrfTokenManager $csrf;

    public function __construct()
    {
        $this->f4 = F4::instance();
        $this->csrf = $this->f4->getDI(CsrfTokenManager::class);
    }

    protected function beforeRoute($req, Response $res, array $params): bool 
    { 
        return true; //Возвращаем false если есть ошибка
    } 

    protected function render(Response $res, string $pageTemplate, array $arParams = [])
    {
        $app = app();
        $app->setContent('body',$pageTemplate);
        if(!empty($arParams['meta'])){
            foreach ($arParams['meta'] as $key => $value) {
                $app->setMeta($key,$value);
            }
        }
        $arParams['csrf'] = [];
        if ($this->csrf) {
            $arParams['csrf'] = [
                'token' => $this->csrf->token(),
                'key' => $this->csrf->getTokenKey()
            ];
            $this->f4->set('_csrf',$arParams['csrf']);
        }
        $html = $app->render($arParams);          
        return $res->withBody($html);  
    }

    protected function afterRoute($req, Response $res, array $params): void 
    {}

    protected function p404(Response $res, string $mess='Страница не найдена', string $page = 'include/block404.php'):Response 
    {
        return $this->render($res->withStatus(404), $page, [
            'title' => $mess
        ]);
    }
    
}
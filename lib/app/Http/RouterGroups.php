<?php

namespace App\Http;

/**
* === RouterGroups: одна активная группа, только URL-chain + group-middleware
*/
class RouterGroups {
    private Router $router;
    private string $chainPrefix = '';
    private MiddlewareDispatcher $mw;
    private bool $flagMiddleware = true;


    /**
    * @param string|array $chainUrlGroup строка типа "/admin/v1" или массив ["admin","v1"].
    */
    public function __construct(Router $router, $chainUrlGroup = ''){
        $this->router = $router;
        $this->chainPrefix = self::normalizeChain($chainUrlGroup);
        $this->mw = new MiddlewareDispatcher();
    }


    public static function normalizeChain($chain): string {
        if (is_array($chain)) {
            $chain = implode('/', array_map(fn($s)=>trim((string)$s,'/'), $chain));
        }
        $chain = (string)$chain;
        return trim($chain);
    }


    public function chainPrefix(): string { return $this->chainPrefix; }

    //Запрещаем добавлять middleware после добавления роутеров, чтоб избежать скрытой ошибки
    public function flagMW(): void {
        $this->flagMiddleware = false;
    }

    public function add(callable $mw): self { 
        if(!$this->flagMiddleware) user_error('RouterGroups: There is a ban on adding middleware after adding a route',E_USER_ERROR);
        $this->mw->add($mw); return $this; 
    }
    /** @return callable[] */
    public function middlewares(): array { return $this->mw->all(); }


    public function end(): void { $this->router->clearGroup($this); }
}
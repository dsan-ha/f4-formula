<?php
namespace Test\Component;

use App\Component\BaseComponent;

class TestComponent extends BaseComponent
{
    public function execute(): void
    {
        $this->arResult = [];
    }
}
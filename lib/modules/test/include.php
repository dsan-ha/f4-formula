<?php
// тут можно регать роуты, события, компоненты и т.д.
$f4 = \App\F4::instance();

// пример: просто метка
$f4->set('module.test.mess', \Test\Main::get_test());

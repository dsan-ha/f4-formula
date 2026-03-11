<?php
declare(strict_types=1);

use App\Modules\Install\InstallerBase;

final class TestInstall extends InstallerBase
{
    public function install(): void
    {
        // UI уже скопирован автоинсталлером,
        // тут делай всё что “как в битрикс”: миграции, дефолтные опции, сиды и т.д.

        // пример:
        // $this->f4->set('some.flag', true);
    }

    public function uninstall(): void
    {
        // на будущее: сюда логика удаления (таблицы, опции и т.п.)
        // UI-файлы можно удалять по манифесту (добавим позже отдельной командой/методом)
    }
}

return TestInstall::class;
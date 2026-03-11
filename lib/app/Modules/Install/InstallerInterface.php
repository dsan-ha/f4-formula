<?php
declare(strict_types=1);

namespace App\Modules\Install;

interface InstallerInterface
{
    public function install(): void;
    public function uninstall(): void;
}
<?php
namespace App\Migrations;

use Phinx\Migration\AbstractMigration;
use App\F4;
use App\Service\DB\SQL;

abstract class ModuleMigration extends AbstractMigration
{
    protected ?F4 $f4 = null;
    protected ?SQL $db = null;

    abstract public static function moduleSlug(): string;

    protected function f4(): F4
    {
        return $this->f4 ??= F4::instance();
    }

    protected function db(): SQL
    {
        if ($this->db) return $this->db;

        /** @var SQL $db */
        $db = $this->f4()->getDI(SQL::class);
        return $this->db = $db;
    }

    protected function moduleBasePath(): string
    {
        $modules = (array)$this->f4()->get('MODULES');
        $slug = static::moduleSlug();

        if (empty($modules[$slug]['base_path'])) {
            throw new \RuntimeException("Module not found in registry: {$slug}");
        }

        return rtrim((string)$modules[$slug]['base_path'], '/\\');
    }

    protected function snapshotDir(): string
    {
        return SITE_ROOT . 'local/backups/snapshots/' . static::moduleSlug();
    }
}
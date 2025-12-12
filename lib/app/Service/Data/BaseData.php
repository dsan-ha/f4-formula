<?php

namespace App\Service\Data;

use App\Service\DataManager;
use App\Service\DataEntityInterface;

class BaseData extends DataManager implements DataEntityInterface
{
    public static function getTableName(): string
    {
        return 'base_table';
    }

    public static function getFieldsMap(): array
    {
        return [
            'id'    => ['type'=>'int',  'pkey'=>true, 'required'=>true],
            'name'  => ['type'=>'string', 'len'=>200],
            'email' => ['type'=>'string', 'len'=>200],
            'age'   => ['type'=>'int'],
            'data'  => ['type'=>'json'],
            'user_id' => ['type'=>'int', 'required'=>true, 'ref' => [
                'type'    => 'hasOne',            // hasOne|belongsTo|hasMany
                'table'   => 'users',
                'local'   => 'user_id',
                'foreign' => 'id',
                'alias'   => 'user',              // имя отношения в with[]
                'join'    => 'LEFT',              // для авто-JOIN
                'fields'  => ['id','name','email'] // селектируемые поля при join
            ]],
            // adhoc/вычисляемые можно объявлять как:
            // 'orders_cnt' => ['type'=>'int', 'virtual'=>true]
        ];
    }

    public static function getDtoClass(): ?string
    {
        return null; // либо null → массивы
    }
}
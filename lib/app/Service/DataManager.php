<?php
namespace App\Service;

use App\F4;
use App\Service\DataManagerProtector;
use App\Service\DB\SQL;
use App\Service\DB\Cursor;
use App\Service\DB\QueryBuilder;
use App\Utils\Cache as BaseCache;
use App\Service\DB\Cache as DBCache;
use App\Service\Hydrator\HydratorInterface;

/**
 * Абстрактный класс для работы с таблицами через SQL или Mapper (Fat-Free Framework).
 *
 * Методы:
 * - getList() — построение запроса с фильтрацией, сортировкой, группировкой и join'ами
 * - getRaw() — ручной SQL
 * - add(), update(), delete() — работа с DB\SQL\Mapper
 */
abstract class DataManager {
    protected SQL $db;
    protected F4 $f4;
    protected DBCache $cache;
    protected QueryBuilder $qb;
    protected HydratorInterface $hydrator;
    protected DataManagerProtector $protector;

    /**
     * @param SQL $db экземпляр базы данных
     * @param F4 $f4 экземпляр фреймворка
     */
    public function __construct(SQL $db, F4 $f4, HydratorInterface $hydrator,BaseCache $cache ) {
        $this->db = $db;
        $this->f4 = $f4;
        $this->hydrator = $hydrator;
        $this->cache = new DBCache($cache);
        $this->protector = $f4->getDI(DataManagerProtector::class);
        $fieldsMap = static::getFieldsMap();
        $table     = static::getTableName();
        $this->qb = new QueryBuilder($db, $table, $fieldsMap, pk: null, hydrator: $this->hydrator);
    }

    public function logSQL(){
        return str_replace(PHP_EOL, '<br>', $this->db->log());
    }

    public function getDB(){
        return $this->db;
    }

    /**
     * Возвращает имя таблицы (обязательный метод в потомке)
     * @return string
     */
    abstract public static function getTableName(): string;

    /**
     * Возвращает список доступных для записи полей
     * @return string[]
     */
    abstract public static function getFieldsMap(): array;

    protected function validate(array &$data, bool $onUpdate): void
    {
        $fieldsMap = static::getFieldsMap();
        foreach ($fieldsMap as $field => $rules) {
            $isPresent = array_key_exists($field, $data);
            $required  = (bool)($rules['required'] ?? false);
            // defaults только на insert
            if (!$onUpdate && !$isPresent && array_key_exists('default', $rules)) {
                $data[$field] = is_callable($rules['default']) ? $rules['default']() : $rules['default'];
                $isPresent = true;
            }
            if ($required && !$onUpdate && !$isPresent) {
                throw new \InvalidArgumentException("Field '$field' is required");
            }
            if (!$isPresent) continue;

            $val = $data[$field];

            if (!empty($rules['type'])) {
                $type = $rules['type'];
                switch ($type) {
                    case 'string':
                        if (!is_string($val)) throw new \InvalidArgumentException("Field '$field' must be string");
                        break;
                    case 'int':
                        if (!is_int($val) && !(is_numeric($val) && (int)$val == $val)) {
                          throw new \InvalidArgumentException("Field '$field' must be int");
                        }
                        $data[$field] = (int)$val;
                        break;
                    case 'float':
                        if (!is_float($val) && !is_numeric($val)) {
                          throw new \InvalidArgumentException("Field '$field' must be float");
                        }
                        $data[$field] = (float)$val;
                        break;
                    case 'bool':
                        $data[$field] = (bool)$val;
                        break;
                    case 'date':
                    case 'datetime':
                        $ts = strtotime((string)$val);
                        if ($ts === false) throw new \InvalidArgumentException("Field '$field' must be $type");
                        // можно нормализовать формат
                            break;
                    case 'json':
                        if(!empty($val)){
                            if (is_array($val)) { $data[$field] = json_encode($val, JSON_UNESCAPED_UNICODE); break; }
                            json_decode((string)$val);
                            if (json_last_error() !== JSON_ERROR_NONE) throw new \InvalidArgumentException("Field '$field' must be valid JSON");
                        }
                        break;
                    default:
                        // кастомный тип — пропустим, либо кинем исключение
                        break;
                }
            }

            if (isset($rules['length']) && is_string($data[$field])) {
                $len = mb_strlen($data[$field]);
                if ($len > $rules['length']) {
                     throw new \InvalidArgumentException("Field '$field' length must be <= {$rules['length']}");
                }
            }
        }
    }

    protected function filterKnown(array $data, bool $strict = true): array
    {
        $fieldsMap = static::getFieldsMap();
        $known = array_intersect_key($data, $fieldsMap);
        if ($strict && count($known) !== count($data)) {
            $unknown = array_diff_key($data, $fieldsMap);
            throw new \InvalidArgumentException('Unknown fields: ' . implode(', ', array_keys($unknown)));
        }
        return $known;
    }


    /**
     * Получить запись по ID
     * @param int $id
     * @return array|null
     */
    public function getById(int $id, array $cache = []): object|array|null {
        $tags = ['tbl:'.static::getTableName(), 'id:'.$id];
        $options = ['where' => ['=id'=>$id], 'limit'=>1];
        $key  = !empty($cache['key']) ? $cache['key'] : $this->buildKeyFromBacktrace($options);
        $cur = $this->cacheRemember($cache, function() use ($options) {
            return $this->qb->find($options);
        },$key,$tags);
        return $cur->first();
    }

    /**
     * Основной метод выборки данных
     * @param array $options: ['where'=>['age >' => 18],'joins'=>[],'with'  => ['user'], 'group'=>'','select'=>[], 'having'=>'', 'order'=>'col DESC', 'limit'=>N, 'offset'=>M]
     * @return array[]

     */
    public function getList(
        array $options = [],
        array $cache = []
    ): Cursor {
        if(!empty($options['joins'])){
            foreach ($options['joins'] as &$join) {
                $join = $this->normalizeJoin($join);
            }
        } 
        $this->protector->assertSafeSelect($options);
        $tags = ['tbl:'.static::getTableName()];
        $key  = !empty($cache['key']) ? $cache['key'] : $this->buildKeyFromBacktrace($options);
        return $this->cacheRemember($cache, function() use ($options) {
            return $this->qb->find($options);
        }, $key, $tags);
    }

    public function count(array $options=[], array $cache=[]): int {
        $tags = ['tbl:'.static::getTableName()];
        $key  = !empty($cache['key']) ? $cache['key'] : $this->buildKeyFromBacktrace($options);
        return $this->cacheRemember($cache, function() use ($options) {
            return $this->qb->count($options);
        }, $key, $tags);
    }

    /**
     * Выполняет произвольный SQL-запрос
     * @param string $sql
     * @param array $params параметры для плейсхолдеров ?
     * @return array[]
     */
    public function getRaw(string $sql, array $params = [], array $cache = []): array {
        $this->protector->assertReadOnlyQuery($sql);
        $tags = ['tbl:'.static::getTableName()]; 
        $key  = $cache['key'] ?? $this->buildKeyFromBacktrace($params);
        return $this->cacheRemember($cache, fn()=> $this->db->exec($sql,$params), $key, $tags);
    }

    private function cacheRemember(array $cache, callable $producer, string $key, array $tags = []) {
        $this->normalizeCache($cache);            // ваш метод нормализации
        $ttl  = (int)($cache['ttl'] ?? 0);
        $tags = array_unique(array_merge($tags, $cache['tags'] ?? []));
        return $this->cache->remember($key, $ttl, $tags, $producer);
    }

    // Инвалидация по тэгам из write-путей
    private function invalidateByTables(array $tables): void {
        $tags = array_map(fn($t) => "tbl:".$t, $tables);
        $this->cache->invalidateTags($tags);
    }

    private function buildKeyFromBacktrace($args = []): string {
        // стабильно хешируем «кто/с чем» вызвал
        return 'dm:'.static::class.':'.$this->f4->hash(json_encode($args).'|'.json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)));
    }

    private function normalizeJoin(array $join): ?array {
        // короткая запись без ключей
        if (isset($join[0], $join[1], $join[2])) {
            $arShot = explode(' ',$join[1]);
            return [
                'type'  => strtoupper($join[0]),
                'table' => $arShot[0],
                'alias' => $arShot[1] ?? null, // тянется "table alias"
                'on'    => $join[2],
            ];
        }

        if (!empty($join['type']) && !empty($join['table']) && !empty($join['on'])) {
            return [
                'type'  => strtoupper($join['type']),
                'table' => $join['table'],
                'alias' => $join['alias'] ?? null,
                'on'    => $join['on'],
            ];
        }

        return null; // некорректная запись
    }

    /**
     * Добавляет новую запись
     * @param array $data ассоциативный массив [поле => значение]
     * @return bool
     */
    public function add(array $data): ?int {
        $data = $this->filterKnown($data);
        $this->validate($data, false);
        if (!$data) return null;
        $id = $this->qb->insert($data);
        if ($id) $this->invalidateByTables([static::getTableName()]);
        return $id; // вернёт lastInsertId
    }

    /**
     * Обновляет запись по ID
     * @param int $id
     * @param array $data данные для обновления
     * @return bool
     */
    public function update(int $id, array $data): int {
        $data = $this->filterKnown($data);
        $this->validate($data, true);
        if (!$data) return 0;
        $aff = $this->qb->update($id, $data);
        if ($aff) { 
            $this->invalidateByTables([static::getTableName()]); 
            $this->cache->invalidateTag('id:'.$id); 
        }
        return $aff; // affected rows
    }

    /**
     * Удаляет запись по ID
     * @param int $id
     * @return bool
     */
    public function delete(int $id,string $key = 'id'): bool {
        $ok = $this->qb->erase([$key => $id]) > 0;
        if ($ok) { 
            $this->invalidateByTables([static::getTableName()]); 
            $this->cache->invalidateTag('id:'.$id); 
        }
        return $ok;
    }

    /**
     * Генерация SQL для CREATE TABLE по getFieldsMap() (MySQL).
    * Для полей необходимо проставить поля для генерации nullable=true|false, default, autoIncrement, unique
     * @return string|null SQL если execute=false; null если выполнено.
     */
    public static function createTable(SQL $db, bool $execute = false): ?string
    {
        $table = static::getTableName();
        $map   = static::getFieldsMap();

        $cols = [];
        $pk   = null;
        $uniques = [];
        $fks = [];

        foreach ($map as $name => $rules) {
            $sqlType = match ($rules['type'] ?? 'string') {
                'int'      => 'INT',
                'float'    => 'DOUBLE',
                'bool'     => 'TINYINT(1)',
                'date'     => 'DATE',
                'datetime' => 'DATETIME',
                'json'     => 'JSON',
                default    => 'VARCHAR(' . ($rules['length'] ?? 255) . ')',
            };
            $nullable = !empty($rules['nullable']) ? 'NULL' : 'NOT NULL';
            $default  = '';
            if (array_key_exists('default', $rules)) {
                $def = $rules['default'];
                if ($def === null)       $default = ' DEFAULT NULL';
                elseif (is_bool($def))   $default = ' DEFAULT ' . ($def ? '1':'0');
                elseif (is_numeric($def))$default = ' DEFAULT ' . $def;
                else                     $default = " DEFAULT '" . addslashes((string)$def) . "'";
            }
            $autoInc = !empty($rules['autoIncrement']) ? ' AUTO_INCREMENT' : '';
            $cols[]  = "`$name` $sqlType $nullable$default$autoInc";
            if (!empty($rules['pkey']))   $pk = $name;
            if (!empty($rules['unique'])) $uniques[] = $name;
            if (!empty($rules['ref']) && is_array($rules['ref'])) {
                $ref = $rules['ref'];
                $fkTable = $ref['table']   ?? null;
                $fkField = $ref['foreign'] ?? 'id';
                if ($fkTable) {
                    $idx = "fk_{$table}_{$name}";
                    $fks[] = "CONSTRAINT `$idx` FOREIGN KEY (`$name`) REFERENCES `$fkTable`(`$fkField`) ON DELETE "
                        . strtoupper($ref['onDelete'] ?? 'RESTRICT')
                        . " ON UPDATE " . strtoupper($ref['onUpdate'] ?? 'CASCADE');
                }
            }
        }
        if ($pk) $cols[] = "PRIMARY KEY (`$pk`)";
        foreach ($uniques as $u) $cols[] = "UNIQUE KEY `uniq_{$table}_{$u}` (`$u`)";
        foreach ($fks as $fk)    $cols[] = $fk;

        $sql = "CREATE TABLE IF NOT EXISTS `$table` (\n  " . implode(",\n  ", $cols) . "\n)"
             . " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        if ($execute) { $db->exec($sql); return null; }
        return $sql;
    }
    
    /**
     * Нормализация массива кэша
     * @param array $cache
     * @return array
     */
    public function normalizeCache(array &$cache): void {
        $def = [
            'ttl'=>0, // сек, 0 = без кэша
            'type'=>'N' //Тип кэша
        ];
        $cache = array_merge($def,$cache);
    }

    /**
     * **Транзакции на уровне менеджера**  
     * Вспомогательный хелпер:
     */
    public function tx(callable $fn): mixed {
        $this->db->begin();
        try { $res = $fn($this); $this->db->commit(); return $res; }
        catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }
}

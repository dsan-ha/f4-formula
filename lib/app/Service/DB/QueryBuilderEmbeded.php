<?php
namespace App\Service\DB;

final class Ref { public function __construct(public string $expr) {} }

/**
 * Изолированный билдер сложных фрагментов SQL:
 * - WHERE (вкладываемые AND/OR-группы, операторы перед ключом)
 * - JOIN ... ON (...) (несколько JOIN'ов с деревьями условий в ON)
 * - HAVING (аналогично WHERE)
 *
 * Возвращает "сырые" строки SQL (без префиксов WHERE/HAVING) и массивы аргументов.
 * Совместим с ['where' => ['raw' => [$sql, $args]]]
 */
final class QueryBuilderEmbeded
{
    /** @var callable(string):string */
    private $mapColumn;

    // WHERE
    private array $whereNodes = [];
    private ?string $whereRawSql = null;
    private array $whereRawArgs = [];

    // HAVING
    private array $havingNodes = [];
    private ?string $havingRawSql = null;
    private array $havingRawArgs = [];

    // JOIN
    private array $joinNodes = []; // каждый: ['type','table','alias','on'=>normalized-node]
    private ?string $joinRawSql = null;
    private array $joinRawArgs = [];

    /**
     * @param array $fieldsMap например:
     *  ['name' => 'u.name'] ИЛИ ['name' => ['column' => 'u.name']]
     * @param callable|null $mapColumn кастомный маппер alias->column
     */
    public function __construct(array $fieldsMap = [], ?callable $mapColumn = null)
    {
        if ($mapColumn) {
            $this->mapColumn = $mapColumn;
        } else {
            $this->mapColumn = static function(string $field) use ($fieldsMap): string {
                if (isset($fieldsMap[$field])) {
                    return is_array($fieldsMap[$field]) && isset($fieldsMap[$field]['column'])
                        ? $fieldsMap[$field]['column']
                        : (is_string($fieldsMap[$field]) ? $fieldsMap[$field] : $field);
                }
                return $field;
            };
        }
    }

    /* ===================== WHERE ===================== */

    /** Полностью подменить WHERE сырым SQL */
    public function rawWhere(?string $sql, array $args = []): self {
        $this->whereRawSql = $sql ?: null;
        $this->whereRawArgs = $sql ? $args : [];
        if ($sql) $this->whereNodes = []; // отключаем узлы
        return $this;
    }

    /** Добавить плоский блок условий (AND по умолчанию) */
    public function addWhere(array $cond): self {
        $this->whereNodes[] = $this->normalize($cond);
        return $this;
    }

    /** Добавить группу WHERE ('AND'|'OR') из набора массивов-условий */
    public function groupWhere(string $bool, array $items): self {
        $bool = strtoupper($bool);
        $this->whereNodes[] = ['type'=>'GROUP','bool'=>$bool,'items'=>array_map([$this,'normalize'],$items)];
        return $this;
    }

    public function orWhere(array $items): self { return $this->groupWhere('OR', $items); }
    public function andWhere(array $items): self { return $this->groupWhere('AND', $items); }

    /** Вернуть [sql,args] без префикса "WHERE " */
    public function toWhereRaw(): array {
        if ($this->whereRawSql !== null) return [$this->whereRawSql, $this->whereRawArgs];
        return $this->emit($this->whereNodes);
    }

    /** Упаковка для твоего QueryBuilder: ['where' => ['raw' => [$sql,$args]]] */
    public function forQueryBuilderWhere(): array {
        [$sql,$args] = $this->toWhereRaw();
        return ['raw' => [$sql, $args]];
    }

    /* ===================== HAVING ===================== */

    public function rawHaving(?string $sql, array $args = []): self {
        $this->havingRawSql = $sql ?: null;
        $this->havingRawArgs = $sql ? $args : [];
        if ($sql) $this->havingNodes = [];
        return $this;
    }

    public function addHaving(array $cond): self {
        $this->havingNodes[] = $this->normalize($cond);
        return $this;
    }

    public function groupHaving(string $bool, array $items): self {
        $bool = strtoupper($bool);
        $this->havingNodes[] = ['type'=>'GROUP','bool'=>$bool,'items'=>array_map([$this,'normalize'],$items)];
        return $this;
    }

    public function orHaving(array $items): self { return $this->groupHaving('OR', $items); }
    public function andHaving(array $items): self { return $this->groupHaving('AND', $items); }

    /** Вернуть [sql,args] без префикса "HAVING " */
    public function toHavingRaw(): array {
        if ($this->havingRawSql !== null) return [$this->havingRawSql, $this->havingRawArgs];
        return $this->emit($this->havingNodes);
    }

    /* ===================== JOIN ===================== */

    /**
     * Добавить JOIN.
     * @param string $type   INNER|LEFT|RIGHT|FULL (регистр не важен)
     * @param string $table  "profiles" ИЛИ "profiles p"
     * @param array  $on     условия для ON (поддерживает группы/операторы как в WHERE)
     * @param string|null $alias если нужен отдельный алиас (иначе можно указать в $table)
     */
    public function addJoin(string $type, string $table, array $on, ?string $alias = null): self {
        $this->joinNodes[] = [
            'type'  => strtoupper($type ?: 'INNER'),
            'table' => $table,
            'alias' => $alias,
            'on'    => $this->normalize($on),
        ];
        return $this;
    }

    /** Полностью подменить JOIN-цепочку сырым SQL (можно с плейсхолдерами) */
    public function rawJoins(?string $sql, array $args = []): self {
        $this->joinRawSql = $sql ?: null;
        $this->joinRawArgs = $sql ? $args : [];
        if ($sql) $this->joinNodes = [];
        return $this;
    }

    /** Вернуть [sql,args] с готовой цепочкой "LEFT JOIN ... ON ...  INNER JOIN ..." */
    public function toJoinRaw(): array {
        if ($this->joinRawSql !== null) return [$this->joinRawSql, $this->joinRawArgs];

        $chunks = []; $args = [];
        foreach ($this->joinNodes as $j) {
            $tbl = $j['table'].($j['alias'] ? ' AS '.$j['alias'] : '');
            [$onSql,$onArgs] = $this->emit([$j['on']]); // оборачиваем один узел
            $chunks[] = sprintf(' %s JOIN %s ON %s', $j['type'], $tbl, $onSql);
            $args = array_merge($args, $onArgs);
        }
        return [implode('', $chunks), $args];
    }

    /* ===================== Общая внутрянка ===================== */

    private function parseKey(string $raw): array {
        $raw = trim($raw);
        if (preg_match('/^([A-Z]+)@(.+)$/u', $raw, $m)) return [strtoupper($m[1]), trim($m[2])];
        if (preg_match('/^([=!<>]{1,2})(.+)$/u', $raw, $m)) return [strtoupper($m[1]), trim($m[2])];
        return ['=', $raw];
    }

    private function normalize(array $cond): array {
        // RAW-узел: ['sql' => 'u.deleted = 0', 'args' => []]
        if (isset($cond['sql'])) return ['type'=>'RAW','sql'=>$cond['sql'],'args'=>$cond['args'] ?? []];

        // Группа в декларативном стиле: ['AND'=>[...]] или ['OR'=>[...]]
        if (count($cond) === 1) {
            $k = strtoupper((string)array_key_first($cond));
            if ($k === 'AND' || $k === 'OR') {
                $items = $cond[array_key_first($cond)] ?? [];
                return ['type'=>'GROUP','bool'=>$k,'items'=>array_map([$this,'normalize'],$items)];
            }
        }

        // Плоский блок (AND по умолчанию)
        $items = [];
        foreach ($cond as $key => $val) {
            [$op,$field] = $this->parseKey((string)$key);
            $items[] = ['type'=>'COND','op'=>$op,'field'=>$field,'val'=>$val];
        }
        return ['type'=>'BLOCK','bool'=>'AND','items'=>$items];
    }

    private function emit(array $nodes): array {
        $sqls = []; $args = [];
        foreach ($nodes as $n) {
            switch ($n['type']) {
                case 'RAW':
                    $sqls[] = $n['sql'];
                    $args = array_merge($args, $n['args'] ?? []);
                    break;

                case 'GROUP':
                    [$s,$a] = $this->emit($n['items']);
                    if ($s !== '') { $sqls[] = '('.$s.')'; $args = array_merge($args,$a); }
                    break;

                case 'BLOCK':
                    $sub = []; $subArgs = [];
                    foreach ($n['items'] as $c) {
                        [$s,$a] = $this->emitCond($c);
                        if ($s !== '') { $sub[]=$s; $subArgs = array_merge($subArgs,$a); }
                    }
                    if ($sub) { $sqls[] = '('.implode(' '.$n['bool'].' ', $sub).')'; $args = array_merge($args,$subArgs); }
                    break;
            }
        }
        $joined = implode(' AND ', array_filter($sqls));
        $joined = preg_replace('/^\((.+)\)$/', '$1', $joined); // снять внешние лишние скобки
        return [$joined, $args];
    }

    private function emitCond(array $c): array {
        $op = strtoupper($c['op']);
        $map = $this->mapColumn;
        $f  = $map($c['field']);
        $v  = $c['val'];

        // NULL-семантика '='/'!='
        if ($v === null && ($op === '=' || $op === '!=')) {
            $op = $op === '=' ? 'ISNULL' : 'NOTNULL';
        }

        switch ($op) {
            case 'ISNULL':  return ["{$f} IS NULL", []];
            case 'NOTNULL': return ["{$f} IS NOT NULL", []];

            case '=': case '!=': case '>': case '>=': case '<': case '<=':
            case 'LIKE': case 'ILIKE':
                if ($v instanceof Ref) return ["{$f} {$op} {$v->expr}", []];
                return ["{$f} {$op} ?", [$v]];

            case 'IN': case 'NOTIN':
                $vals = is_array($v) ? array_values($v) : [$v];
                if (!$vals) return [$op==='IN' ? '1=0' : '1=1', []];
                $ph = implode(',', array_fill(0, count($vals), '?'));
                return ["{$f} ".($op==='IN' ? 'IN' : 'NOT IN')." ({$ph})", $vals];

            case 'BETWEEN':
                if (!is_array($v) || count($v)!==2) throw new \InvalidArgumentException('BETWEEN expects [from,to]');
                $a = $v[0] instanceof Ref ? $v[0]->expr : '?';
                $b = $v[1] instanceof Ref ? $v[1]->expr : '?';
                $args = [];
                if (!($v[0] instanceof Ref)) $args[] = $v[0];
                if (!($v[1] instanceof Ref)) $args[] = $v[1];
                return ["{$f} BETWEEN {$a} AND {$b}", $args];

            default:
                throw new \InvalidArgumentException("Unsupported operator: {$op}");
        }
    }
}

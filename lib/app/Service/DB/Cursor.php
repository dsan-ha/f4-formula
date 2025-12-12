<?php
namespace App\Service\DB;

use IteratorAggregate, ArrayIterator, Traversable;

final class Cursor implements IteratorAggregate {
    private array $rows; private int $pos = -1;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function getIterator(): Traversable { return new ArrayIterator($this->rows); }
    public function first(): mixed { $this->pos=0;   return $this->rows[0]??null; }
    public function last(): mixed  { $this->pos=count($this->rows)-1; return $this->rows[$this->pos]??null; }
    public function next(): mixed  { $this->pos++;   return $this->rows[$this->pos]??null; }
    public function prev(): mixed  { $this->pos=max(-1,$this->pos-1); return $this->rows[$this->pos]??null; }
    public function at(int $i):mixed{ $this->pos=$i; return $this->rows[$i]??null; }
    public function count(): int { return count($this->rows); }
    public function toArray(): array { return $this->rows; }
}


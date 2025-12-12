<?php
declare(strict_types=1);

namespace App\Base;

//! PHP magic wrapper
abstract class Magic implements \ArrayAccess {

	abstract public function exists(string $key): bool;
    abstract public function set(string $key, mixed $val): void;
    abstract public function get(string $key, mixed $def = null): mixed;
    abstract public function clear(string $key): void;

	public function offsetExists(mixed $key): bool {
		return $this->exists($key) && $this->get($key)!==NULL;
	}

	public function offsetGet(mixed $key): mixed {
		$val=$this->get($key);
		return $val?:$def;
	}

	public function offsetSet(mixed $key, mixed $val): void {
		$this->set($key,$val);
	}

	public function offsetUnset(mixed $key): void {
		$this->clear($key);
	}

	public function __isset($key): bool {
		return $this->offsetExists($key);
	}

	function __set($key,$val) {
		$this->offsetSet($key,$val);
	}

	function __get($key) {
		$val=$this->offsetGet($key);
		return $val;
	}

	function __unset($key) {
		$this->offsetunset($key);
	}
}

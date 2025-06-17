<?php

trait SingletonTrait
{
	private static array $instances = [];

	public static function getInstance(string $className = null): object
	{
		$class = $className ?: static::class;
		if (!isset(self::$instances[$class])) {
			self::$instances[$class] = new $class();
		}

		return self::$instances[$class];
	}
}

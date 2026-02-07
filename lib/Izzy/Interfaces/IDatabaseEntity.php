<?php

namespace Izzy\Interfaces;

/**
 * Interface for entities that can be persisted to the database.
 */
interface IDatabaseEntity
{
	/**
	 * Get the database table name for this entity.
	 * @return string Table name.
	 */
	public static function getTableName(): string;
}

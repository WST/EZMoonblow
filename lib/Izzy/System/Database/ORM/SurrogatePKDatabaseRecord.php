<?php

namespace Izzy\System\Database\ORM;

use Izzy\System\Database\Database;

abstract class SurrogatePKDatabaseRecord extends DatabaseRecord
{
	/**
	 * Primary key column name.
	 * @var string 
	 */
	protected string $pkField = '';

	/**
	 * Primary key value.
	 * @var int|null 
	 */
	protected ?int $pkValue = NULL;

	/**
	 * Warning: only single column surrogate keys are supported!
	 */
	public function __construct(Database $database, array $row, string $pk_field) {
		$fresh = !isset($row[$pk_field]);
		parent::__construct($database, $row, [$pk_field], $fresh);
		$this->pkField = $pk_field;
		if(isset($row[$pk_field])) {
			$this->pkValue = (int) $row[$pk_field];
		} else {
			$this->pkValue = NULL;
		}
	}

	/**
	 * Return the surrogate primary key value of this object (object ID).
	 * @return int|null The primary key value.
	 */
	public function id(): ?int {
		return $this->pkValue;
	}

	/**
	 * Save the record.
	 * @return bool|int The surrogate primary key value — created or current, or false if saving failed.
	 */
	public function save(): bool|int {
		// Prepare data for writing, removing extra fields that might have been pulled from other tables.
		$table_columns = array_flip($this->database->getFieldList(static::getTableName()));
		$row = array_intersect_key($this->row, $table_columns);

		if($this->isFresh) {
			// Write the data.
			$success = $this->database->insert(static::getTableName(), $row);
			if (!$success) return false;
			$this->pkValue = $this->database->lastInsertId(); // Note: we are not handling a possible false here.
			$this->row[$this->pkField] = $this->pkValue;

			// Mark that the object is no longer new.
			$this->isFresh = false;

			// Return the generated surrogate primary key value.
			return $this->pkValue;
		}

		// Update the data.
		$this->database->update(static::getTableName(), $row, [$this->pkField => $this->pkValue]);

		// Return the current surrogate primary key value.
		return $this->pkValue;
	}

	/**
	 * Remove the record.
	 */
	public function remove(): bool {
		// It’s impossible to remove something that isn’t in the table yet.
		if($this->isFresh) return false;

		// Deleting.
		if(is_int($this->pkValue)) {
			return $this->database->delete(static::getTableName(), [$this->pkField => $this->pkValue]);
		}
	}
}

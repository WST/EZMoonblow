<?php

namespace Izzy\System\Database;

use ArrayAccess;

/**
 * Database record.
 */
abstract class DatabaseRecord implements ArrayAccess
{
	/**
	 * Link with the database.
	 * @var Database 
	 */
	protected Database $database;
	
	/**
	 * Name of the database table storing objects of this kind.
	 */
	protected string $table = '';

	/**
	 * Data row.
	 */
	protected array $row = [];

	/**
	 * List of field names that form the primary key, if any (otherwise NULL).
	 */
	protected ?array $pkFields = NULL;

	/**
	 * Primary key values.
	 */
	protected array $pkValues = [];

	/**
	 * URL of the web page representing the object.
	 */
	protected string $url = '';

	/**
	 * Values that need to be written to the database for boolean type fields.
	 */
	protected array $booleans = ['on', 'off'];

	/**
	 * Prefix for field names of objects of this type.
	 */
	protected string $fieldNamePrefix = '';

	/**
	 * Flag indicating the “freshness” of the object, i.e. the object has not yet been saved to the database.
	 */
	protected bool $isFresh = false;

	/**
	 * Constructor. The table may have a primary key consisting of columns $pkFields, in which case
	 * it will be possible to save the object to the database by calling save().
	 */
	public function __construct(
		Database $database,
		string $table,
		array $row,
		?array $pkFields = NULL,
		bool $isFresh = false
	) {
		$this->database = $database;
		$this->table = $table;
		$this->row = $row;
		$this->pkFields = $pkFields;
		$this->isFresh = $isFresh;

		// If columns forming a natural primary key are specified, mark this.
		if(is_array($pkFields) && count($pkFields)) {
			foreach($pkFields as $pkPartField) {
				$this->pkValues[$pkPartField] = & $this->row[$pkPartField];
			}
		}
	}

	/**
	 * Save the record.
	 * @return bool|int Success status. Int may only be returned by child class that implements
	 * surrogate PK handling, this method only returns booleans.
	 */
	public function save(): bool|int {
		// Prepare the data, keeping in mind that it may have extra fields.
		$table_columns = array_flip($this->database->getFieldList($this->table));
		$row = array_intersect_key($this->row, $table_columns);

		// Save the data and indicate it.
		if($this->isFresh) {
			// A fresh record.
			$success = $this->database->insert($this->table, $row);
			$this->isFresh = false;
		} else {
			// An already existing record.
			$success = $this->database->update($this->table, $row, $this->pkValues);
		}

		return $success;
	}

	public function remove(): bool {
		// It’s impossible to delete a new record that isn’t in the database yet.
		if($this->isFresh) return false;

		// Can’t delete something unclear.
		if(!count($this->pkValues)) return false;

		// Build the condition.
		$where = [];
		foreach($this->pkValues as $field => $value) {
			$where[] = "$field = " . $this->database->quote($value);
		}

		// Write the modifications.
		$this->database->delete($this->table, implode(' AND ', $where));

		// Deletion successful.
		return true;
	}

	public function isNew(): bool {
		return $this->isFresh;
	}

	/**
	 * Check for the existence of a field with index $offset.
	 * @param mixed $offset The index to check.
	 * @return bool Whether a field with such an index exists.
	 */
	public function offsetExists(mixed $offset): bool {
		return isset($this->row[$offset]);
	}

	/**
	 * Return the value of the field with index $offset.
	 * @param mixed $offset The element index.
	 * @return mixed The element value.
	 */
	public function offsetGet(mixed $offset): mixed {
		return $this->row[$offset];
	}

	/**
	 * Set the field with index $offset to value $value.
	 * @param mixed $offset The element index.
	 * @param mixed $value The required value.
	 */
	public function offsetSet(mixed $offset, mixed $value): void {
		$this->row[$offset] = $value;
	}

	/**
	 * Remove the element with index $offset.
	 * @param mixed $offset The element index.
	 */
	public function offsetUnset(mixed $offset): void {
		unset($this->row[$offset]);
	}

	/**
	 * Set values for writing to boolean fields.
	 */
	public function setBooleanValues($true_placeholder, $false_placeholder): void {
		$this->booleans = [$true_placeholder, $false_placeholder];
	}

	/**
	 * Set the prefix for database field names.
	 */
	public function setFieldNamePrefix(string $fieldNamePrefix): void {
		$this->fieldNamePrefix = $fieldNamePrefix;
	}
}

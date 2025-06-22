<?php

namespace Izzy;

use PDO;
use PDOException;

/**
 * Database wrapper class providing a simplified interface for MySQL operations.
 * Supports prepared statements for security and provides convenient methods
 * for common database operations like select, insert, update, and delete.
 */
class Database
{
	/** @var PDO Database connection instance */
	private PDO $pdo;
	
	/** @var string Database host address */
	private string $host;
	
	/** @var string Database username for authentication */
	private string $username;
	
	/** @var string Database password for authentication */
	private string $password;
	
	/** @var string Database name to connect to */
	private string $dbname;

	/**
	 * Initialize database connection parameters.
	 * @param string $host Database server hostname or IP address
	 * @param string $dbname Name of the database to connect to
	 * @param string $username Database user username
	 * @param string $password Database user password
	 */
	public function __construct($host, $dbname, $username, $password) {
		$this->host = $host;
		$this->dbname = $dbname;
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * Establish connection to the MySQL database using PDO.
	 * @return bool True if connection successful, false otherwise
	 */
	public function connect(): bool {
		try {
			// Create PDO instance with MySQL driver
			$this->pdo = new PDO(
				"mysql:host={$this->host};dbname={$this->dbname}",
				$this->username, $this->password
			);
			return true;
		}  catch (PDOException $e) {
			// Connection failed, return false instead of throwing exception
			return false;
		}
	}

	/**
	 * Close the database connection by unsetting the PDO instance.
	 */
	public function close(): void {
		unset($this->pdo);
	}

	/**
	 * Execute a raw SQL query and return a single row as associative array.
	 * @param string $sql SQL query to execute
	 * @return array|null Associative array of the row data or null if no results
	 */
	public function queryOneRow(string $sql): ?array {
		$statement = $this->pdo->query($sql);
		if (!$statement) return null;
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		return $row !== false ? $row : null;
	}

	/**
	 * Execute a raw SQL query and return all rows as associative arrays.
	 * @param string $sql SQL query to execute
	 * @return array Array of associative arrays representing the rows
	 */
	public function queryAllRows(string $sql): array {
		$statement = $this->pdo->query($sql);
		if (!$statement) return [];
		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Build WHERE clause and parameters from string or array condition.
	 * This method handles both simple string conditions and array-based conditions
	 * where array keys represent field names and values represent field values.
	 * 
	 * @param string|array $where Condition as string (e.g., "id = :id") or array (e.g., ["id" => 1, "status" => "active"])
	 * @return array{0: string, 1: array} Returns tuple of [whereClause, parameters]
	 */
	private function buildWhereClause(string|array $where): array {
		// If where is a string, return it as-is with empty parameters
		if (is_string($where)) {
			return [$where, []];
		}
		
		// If where array is empty, return condition that matches all rows
		if (empty($where)) {
			return ['1', []];
		}
		
		$conditions = [];
		$parameters = [];
		
		// Build conditions for each field-value pair in the array
		foreach ($where as $field => $value) {
			$conditions[] = "`$field` = :$field";
			$parameters[$field] = $value;
		}
		
		// Join all conditions with AND operator
		return [implode(' AND ', $conditions), $parameters];
	}

	/**
	 * Select a single row from the specified table with optional conditions.
	 * @param string $table Name of the table to query
	 * @param string $what Columns to select (default: all columns '*')
	 * @param string|array $where WHERE condition as string or array (default: '1' - all rows)
	 * @return array|null Associative array of the row data or null if no results
	 */
	public function selectOneRow(string $table, string $what = '*', string|array $where = '1'): ?array {
		// Build WHERE clause and extract parameters
		[$whereClause, $whereParams] = $this->buildWhereClause($where);
		$sql = "SELECT $what FROM `$table` WHERE $whereClause";
		
		// If no parameters, use simple query
		if (empty($whereParams)) {
			return $this->queryOneRow($sql);
		}
		
		// Use prepared statement for parameterized queries
		$stmt = $this->pdo->prepare($sql);
		if (!$stmt->execute($whereParams)) {
			return null;
		}
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row !== false ? $row : null;
	}

	/**
	 * Select multiple rows from the specified table with optional conditions, ordering, and pagination.
	 * @param string $table Name of the table to query
	 * @param string $what Columns to select (default: all columns '*')
	 * @param string|array $where WHERE condition as string or array (default: '1' - all rows)
	 * @param string $order ORDER BY clause (default: empty - no ordering)
	 * @param int|null $limit Maximum number of rows to return (default: null - no limit)
	 * @param int|null $offset Number of rows to skip for pagination (default: null - no offset)
	 * @return array Array of associative arrays representing the rows
	 */
	public function selectAllRows(
		string $table,
		string $what = '*',
		string|array $where = '1',
		string $order = '',
		?int $limit = null,
		?int $offset = null,
	): array {
		// Build WHERE clause and extract parameters
		[$whereClause, $whereParams] = $this->buildWhereClause($where);
		$sql = "SELECT $what FROM `$table` WHERE $whereClause";

		// Add ORDER BY clause if specified
		if (!empty($order)) {
			$sql .= " ORDER BY $order";
		}

		// Add LIMIT clause if specified
		if (!is_null($limit)) {
			$sql .= " LIMIT $limit";
			// Add OFFSET clause if specified
			if (!is_null($offset)) {
				$sql .= " OFFSET $offset";
			}
		}

		// If no parameters, use simple query
		if (empty($whereParams)) {
			return $this->queryAllRows($sql);
		}
		
		// Use prepared statement for parameterized queries
		$stmt = $this->pdo->prepare($sql);
		if (!$stmt->execute($whereParams)) {
			return [];
		}
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
	
	/**
	 * Check if a table exists in the database.
	 * @param string $table Name of the table to check
	 * @return bool True if table exists, false otherwise
	 */
	public function tableExists(string $table): bool {
		$statement = $this->pdo->prepare("SHOW TABLES LIKE :table");
		$statement->execute(['table' => $table]);
		return $statement->fetchColumn() !== false;
	}

	/**
	 * Execute an SQL query that doesn't return any rows (INSERT, UPDATE, DELETE, etc.).
	 * @param string $sql SQL query to execute
	 * @return false|int Number of affected rows or false if query failed
	 */
	public function exec(string $sql): false|int {
		return $this->pdo->exec($sql);
	}

	/**
	 * Create a new table in the database with specified fields, keys, and engine.
	 * @param string $table Name of the table to create
	 * @param array $fields Array of field definitions where keys are field names and values are field definitions
	 * @param array $keys Array of key definitions where keys are key names and values are arrays of field names
	 * @param string $engine MySQL storage engine (e.g., 'InnoDB', 'MyISAM')
	 * @return bool True if table created successfully, false otherwise
	 */
	public function createTable(string $table, array $fields, array $keys = [], string $engine = 'InnoDB'): bool {
		// We cannot create a table without any columns
		if (empty($fields)) {
			return false;
		}

		// Build field descriptions by mapping field names to their definitions
		$fieldLines = array_map(function($definition, $name) {
		    return "`$name` $definition";
		}, $fields, array_keys($fields));

		// Build key definitions if provided
		$keyLines = [];
		if (!empty($keys)) {
			foreach ($keys as $keyName => $keyFields) {
				$escapedFields = array_map(fn($field) => "`$field`", $keyFields);
				$keyLines[] = "$keyName (" . implode(', ', $escapedFields) . ")";
			}
		}

		// Combine fields and keys
		$allLines = array_merge($fieldLines, $keyLines);

		// Build the resulting SQL query with proper formatting
		$sql = sprintf(
			"CREATE TABLE IF NOT EXISTS `%s` (\n  %s\n) ENGINE=%s DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
			$table,
			implode(",\n  ", $allLines),
			$engine
		);

		// Execute the query and return success status
		try {
			$result = $this->exec($sql);
			return is_int($result);
		} catch (PDOException $e) {
			return false;
		}
	}
	
	/**
	 * Get a database migration manager instance for handling schema migrations.
	 * @return DatabaseMigrationManager Migration manager instance
	 */
	public function migrationManager(): DatabaseMigrationManager {
		return new DatabaseMigrationManager($this);
	}

	/**
	 * Insert data into the specified table using prepared statements for security.
	 * @param string $table Name of the table to insert into
	 * @param array $data Associative array where keys are column names and values are data to insert
	 * @return bool True if insert successful, false otherwise
	 */
	public function insert(string $table, array $data): bool {
		// If the data is empty, we consider our insert successful
		if (empty($data)) {
			return true;
		}

		// Extract column names and create placeholders for prepared statement
		$columns = array_keys($data);
		$placeholders = array_map(fn($col) => ":$col", $columns);
		$escapedColumns = array_map(fn($col) => "`$col`", $columns);

		// Build the INSERT SQL query
		$sql = sprintf(
			"INSERT INTO `%s` (%s) VALUES (%s)",
			$table,
			implode(', ', $escapedColumns),
			implode(', ', $placeholders)
		);

		// Prepare and execute the statement with the data
		$stmt = $this->pdo->prepare($sql);
		return $stmt->execute($data);
	}

	/**
	 * Update data in the specified table where conditions are met.
	 * @param string $table Name of the table to update
	 * @param array $data Associative array where keys are column names and values are new data
	 * @param string|array $where Condition as string (e.g., "id = :id") or array (e.g., ["id" => 1, "status" => "active"])
	 * @return bool True if update successful, false otherwise
	 */
	public function update(string $table, array $data, string|array $where): bool {
		// If the data is empty, we consider our update successful
		if (empty($data)) {
			return true;
		}

		// Create SET clauses for the UPDATE statement
		$columns = array_keys($data);
		$setClauses = array_map(fn($col) => "`$col` = :$col", $columns);

		// Build WHERE clause and extract parameters
		[$whereClause, $whereParams] = $this->buildWhereClause($where);

		// Build the UPDATE SQL query
		$sql = sprintf(
			"UPDATE `%s` SET %s WHERE %s",
			$table,
			implode(', ', $setClauses),
			$whereClause
		);

		// Prepare and execute with combined parameters (data + where parameters)
		$stmt = $this->pdo->prepare($sql);
		return $stmt->execute(array_merge($data, $whereParams));
	}

	/**
	 * Delete records from the specified table where conditions are met.
	 * @param string $table Name of the table to delete from
	 * @param string|array $where Condition as string (e.g., "id = :id") or array (e.g., ["id" => 1, "status" => "inactive"])
	 * @return bool True if delete successful, false otherwise
	 */
	public function delete(string $table, string|array $where): bool {
		// Build WHERE clause and extract parameters
		[$whereClause, $whereParams] = $this->buildWhereClause($where);

		// Build the DELETE SQL query
		$sql = sprintf("DELETE FROM `%s` WHERE %s", $table, $whereClause);

		// Prepare and execute the statement
		$stmt = $this->pdo->prepare($sql);
		return $stmt->execute($whereParams);
	}

	/**
	 * Count the number of rows in the specified table that match the given conditions.
	 * @param string $table Name of the table to count rows from
	 * @param string|array $where WHERE condition as string or array (default: '1' - all rows)
	 * @return int Number of rows matching the conditions, or 0 if query failed
	 */
	public function countRows(string $table, string|array $where = '1'): int {
		// Build WHERE clause and extract parameters
		[$whereClause, $whereParams] = $this->buildWhereClause($where);
		$sql = "SELECT COUNT(*) as count FROM `$table` WHERE $whereClause";

		// If no parameters, use simple query
		if (empty($whereParams)) {
			$result = $this->queryOneRow($sql);
			return $result ? (int) $result['count'] : 0;
		}
		
		// Use prepared statement for parameterized queries
		$stmt = $this->pdo->prepare($sql);
		if (!$stmt->execute($whereParams)) {
			return 0;
		}
		
		$result = $stmt->fetch(PDO::FETCH_ASSOC);
		return $result ? (int) $result['count'] : 0;
	}
	
	public function quote(string $string): string {
		return $this->pdo->quote($string);
	}

	public function setExchangeBalance(string $exchangeName, ?Money $totalBalance): bool {
		if (is_null($totalBalance)) return false;
		$exchangeNameQuoted = $this->quote($exchangeName);
		$totalBalanceQuoted = $this->quote($totalBalance->format());
		$this->exec("REPLACE INTO exchange_balances (name, balance) VALUES ($exchangeNameQuoted, $totalBalanceQuoted)");
		return true;
	}
}

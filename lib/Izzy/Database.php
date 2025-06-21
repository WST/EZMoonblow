<?php

namespace Izzy;

use PDO;
use PDOException;

class Database
{
	private PDO $pdo;
	
	private string $host;
	private string $username;
	private string $password;
	private string $dbname;

	public function __construct($host, $dbname, $username, $password) {
		$this->host = $host;
		$this->dbname = $dbname;
		$this->username = $username;
		$this->password = $password;
	}

	public function connect(): bool {
		try {
			$this->pdo = new PDO(
				"mysql:host={$this->host};dbname={$this->dbname}",
				$this->username, $this->password
			);
			return true;
		}  catch (PDOException $e) {
			return false;
		}
	}

	public function close(): void {
		unset($this->pdo);
	}
	
	public function selectOneRow() {
		
	}
	
	public function selectAllRows(): array {
		
	}
	
	public function tableExists(string $table): bool {
		$statement = $this->pdo->prepare("SHOW TABLES LIKE :table");
		$statement->execute(['table' => $table]);
		return $statement->fetchColumn() !== false;
	}

	/**
	 * Execute an SQL query that doesn’t return any rows.
	 * @param string $sql
	 * @return false|int number of affected rows or false otherwise.
	 */
	public function exec(string $sql): false|int {
		return $this->pdo->exec($sql);
	}

	/**
	 * Create the table named $table in the database.
	 * @param string $table
	 * @param array $fields
	 * @param string $engine
	 * @return bool
	 */
	public function createTable(string $table, array $fields, string $engine): bool {
		// We cannot create a table without any columns.
		if (empty($fields)) {
			return false;
		}

		// Build field descriptions.
		$fieldLines = array_map(function($definition, $name) {
		    return "`$name` $definition";
		}, $fields, array_keys($fields));

		// Build the resulting SQL query.
		$sql = sprintf(
			"CREATE TABLE IF NOT EXISTS `%s` (\n  %s\n) ENGINE=%s DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
			$table,
			implode(",\n  ", $fieldLines),
			$engine
		);

		// Execute the query.
		$result = $this->exec($sql);
		return is_int($result);
	}
	
	public function migrationManager(): DatabaseMigrationManager {
		return new DatabaseMigrationManager($this);
	}

	public function insert(string $table, array $data) {
		
	}
}

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
	
	public function selectAllRows() {
		
	}
	
	public function tableExists(string $table): bool {
		$statement = $this->pdo->prepare("SHOW TABLES LIKE :table");
		$statement->execute(['table' => $table]);
		return $statement->fetchColumn() !== false;
	}

	/**
	 * Execute the set of database migrations.
	 * @return void
	 */
	public function runMigrations(): void {
		// First, create a Migration Manager instance.
		$manager = new DatabaseMigrationManager($this);
		
		// Our database migrations.
		$phpFiles = glob(IZZY_MIGRATIONS . '/*.php');

		// Some checks to exclude obviously non-migration files.
		$phpFiles = array_filter($phpFiles, function($migrationFile) {
			return is_file($migrationFile) && is_readable($migrationFile);
		});

		// Let’s build the array of the migration files.
		$migrationFiles = [];
		foreach ($phpFiles as $file) {
			$matches = [];
			if (!preg_match('#(\\d{10})\-[0-9a-z\-_]+\.php$#', $file, $matches)) continue;
			$timestamp = (int)$matches[1];
			$migrationFiles[$timestamp] = $file;
		}

		// We should always execute migrations in correct order.
		ksort($migrationFiles);
		
		// Finally, let’s execute the migrations.
		array_walk($migrationFiles, function(& $file) use ($manager) {
			require $file;
		});
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
}

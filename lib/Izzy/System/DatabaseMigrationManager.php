<?php

namespace Izzy\System;

class DatabaseMigrationManager
{
	private Database $db;
	private Logger $logger;

	/**
	 * Padding for nicer console output. 
	 */
	private int $padding = 0;

	private int $paddingWidth = 2;

	/**
	 * Tracks current migration status: if one action fails, we consider the whole migration failed. 
	 */
	private bool $currentStatus = true;

	/**
	 * Cache of the already applied migration numbers.
	 * @var int[] 
	 */
	private array $alreadyAppliedMigrations = [];

	public function __construct(Database $db) {
		$this->db = $db;
		$this->logger = Logger::getLogger();
		
		// If the migrations table does not exist, create it.
		if(!$this->tableExists('migrations')) {
			$fields = [
				'number' => 'INT UNSIGNED NOT NULL DEFAULT 0',
			];
			$this->createTable('migrations', $fields, [], 'InnoDB');
		} else {
			$rows = $this->db->selectAllRows('migrations');
			foreach($rows as $row) {
				$number = (int) $row['number'];
				$this->alreadyAppliedMigrations[$number] = $number;
			}
		}
	}
	
	protected function logDatabaseOperationWithStatus(string $description, bool $successful = true): void {
		$status = $successful ? "\033[48;5;22;1m ✅  OK  \033[0m" : "\033[41m ❌ fail \033[0m";
		$this->logger->info(str_repeat(' ', $this->padding * $this->paddingWidth) . $status . ' ' . $description);
	}

	protected function logDatabaseOperation(string $description): void {
		$this->logger->info(str_repeat(' ', $this->padding * $this->paddingWidth) . $description);
	}

	/**
	 * Check if the given table exists and log the message.
	 * @param string $table
	 * @return bool
	 */
	public function tableExists(string $table): bool {
		$this->logger->info("Checking if table “{$table}” exists...");
		$exists = $this->db->tableExists($table);
		if ($exists) {
			$this->logger->info("Table {$table} exists.");
		} else {
			$this->logger->info("Table {$table} does not exist.");
		}
		return $exists;
	}
	
	/**
	 * Creates a new table in the database with specified fields and keys.
	 * 
	 * @param string $table Name of the table to create
	 * @param array $fields Array of table fields
	 * @param array $keys Array of table keys (optional)
	 * @param string $engine Table engine (default: InnoDB)
	 * 
	 * Usage example:
	 * ```php
	 * $fields = [
	 *   'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
	 *   'name' => 'VARCHAR(255) NOT NULL',
	 *   'email' => 'VARCHAR(255) NOT NULL',
	 *   'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
	 * ];
	 * 
	 * $keys = [
	 *   'PRIMARY KEY' => ['id'],
	 *   'UNIQUE KEY email_idx' => ['email'],
	 *   'KEY name_created_idx' => ['name', 'created_at']
	 * ];
	 * 
	 * $manager->createTable('users', $fields, $keys, 'InnoDB');
	 * ```
	 */
	public function createTable(string $table, array $fields, array $keys = [], string $engine = 'InnoDB'): void {
		$success = $this->db->createTable($table, $fields, $keys, $engine);
		if (!$success) {
			$this->currentStatus = false;
		}
		$this->logDatabaseOperationWithStatus("Creating table “{$table}”...", $success);
	}
	
	public function exec(): void {
		
	}

	private function requireIfValid(string $filename): void {
		$output = null;
		$code = null;

		exec('php -l ' . escapeshellarg($filename), $output, $code);
		if ($code !== 0) {
			$this->currentStatus = false;
		}

		// The variable "$manager" will be available within the migration body.
		$manager = $this;
		require $filename;
	}

	private function runMigration(int $number, string $filename): void {
		// Inform the user.
		$basename = basename($filename);
		$this->logDatabaseOperation("Running migration {$basename}...");
		$this->increasePadding();
		
		// By default, we assume successful execution.
		$this->currentStatus = true;
		
		// Pass the control to the migration file.
		$this->requireIfValid($filename);
		
		// If the migration was successful, mark it as applied.
		if ($this->currentStatus) {
			$this->markApplied($number);
		}
		
		// Report the migration status.
		$statusText = $this->currentStatus ? 'finished' : 'failed';
		$this->logDatabaseOperationWithStatus("Migration {$basename} {$statusText}.", $this->currentStatus);
		$this->resetPadding();
	}

	/**
	 * Checks whether the migration with number $number is a new migration.
	 * @param int $number
	 * @return bool
	 */
	private function isNewMigration(int $number): bool {
		return !array_key_exists($number, $this->alreadyAppliedMigrations);
	}

	/**
	 * Mark the migration with number $number already applied.
	 * @param int $number
	 * @return void
	 */
	private function markApplied(int $number): void {
		$this->alreadyAppliedMigrations[$number] = true;
		$this->db->insert('migrations', ['number' => $number]);
	}

	/**
	 * Execute the set of database migrations.
	 * @return void
	 */
	public function runMigrations(): void {
		$manager = $this;

		// Our database migrations.
		$phpFiles = glob(IZZY_MIGRATIONS . '/*.php');

		// Some checks to exclude obviously non-migration files.
		$phpFiles = array_filter($phpFiles, function($migrationFile) {
			return is_file($migrationFile) && is_readable($migrationFile);
		});

		// Let's build the array of the migration files.
		$migrationFiles = [];
		foreach ($phpFiles as $file) {
			$matches = [];
			if (!preg_match('#(\\d{10})\-[0-9a-z\-_]+\.php$#', $file, $matches)) continue;
			$timestamp = (int)$matches[1];
			$migrationFiles[$timestamp] = $file;
		}

		// Now, we exclude the already applied migrations.
		$migrationFiles = array_filter($migrationFiles, function(int $number) use ($manager) {
			return $manager->isNewMigration($number);
		}, ARRAY_FILTER_USE_KEY);

		// We should always execute migrations in correct order.
		ksort($migrationFiles);
		
		// If there is no new migrations, inform the user that the database is OK.
		if (empty($migrationFiles)) {
			$this->logger->info("Database is up to date.");
		}

		// Finally, let's execute the migrations.
		array_walk($migrationFiles, function($filename, $number) use ($manager) {
			$manager->runMigration($number, $filename);
		});
	}
	
	public function increasePadding(): void {
		$this->padding ++;
	}
	
	public function decreasePadding(): void {
		$this->padding --;
	}
	
	public function resetPadding(): void {
		$this->padding = 0;
	}
}

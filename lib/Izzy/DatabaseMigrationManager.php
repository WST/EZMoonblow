<?php

namespace Izzy;

class DatabaseMigrationManager
{
	private Database $db;
	private Logger $logger;

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
			$this->createTable('migrations', $fields, 'InnoDB');
		} else {
			$rows = $this->db->selectAllRows('migrations');
			foreach($rows as $row) {
				$number = (int) $row['number'];
				$this->alreadyAppliedMigrations[$number] = $number;
			}
		}
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
	
	public function createTable(string $table, array $fields, string $engine = 'InnoDB'): void {
		$this->logger->info("Creating the table “{$table}”...");
		$success = $this->db->createTable($table, $fields, $engine);
		if (!$success) {
			$this->currentStatus = false;
		}
	}
	
	public function runSQL() {
		
	}

	private function runMigration(int $number, string $filename): void {
		// By default, we assume successful execution.
		$this->currentStatus = true;
		
		// The variable “$manager” will be available within the migration body.
		$manager = $this;
		require $filename;
		
		// If the migration was successful, mark it as applied.
		if ($this->currentStatus) {
			$this->markApplied($number);
		}
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

		// Let’s build the array of the migration files.
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

		// Finally, let’s execute the migrations.
		array_walk($migrationFiles, function($filename, $number) use ($manager) {
			$manager->runMigration($number, $filename);
		});
	}
}

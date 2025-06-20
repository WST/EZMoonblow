<?php

namespace Izzy;

class DatabaseMigrationManager
{
	private Database $db;
	private Logger $logger;

	public function __construct(Database $db) {
		$this->db = $db;
		$this->logger = Logger::getLogger();
		
		// If the migrations table does not exist, create it.
		if(!$this->tableExists('migrations')) {
			$fields = [
				'filename' => 'VARCHAR(255)',
			];
			$this->createTable('migrations', $fields, 'InnoDB');
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
		$this->db->createTable($table, $fields, $engine);
	}
	
	public function runSQL() {
		
	}
}

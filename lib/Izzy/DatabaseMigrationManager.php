<?php

namespace Izzy;

class DatabaseMigrationManager
{
	private Database $db;
	
	public function __construct(Database $db) {
		$this->db = $db;
		
		// If the migrations table does not exist, create it.
		if(!$db->tableExists('migrations')) {
			$fields = [
				'filename VARCHAR(255)',
			];
			$db->createTable('migrations', $fields, 'InnoDB', 'utf8mb4');
		}
	}
	
	public function runSQL() {
		
	}
}

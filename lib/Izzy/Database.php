<?php

namespace Izzy;

use PDO;

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

	public function connect() {
		$this->pdo = new PDO(
			"mysql:host={$this->host};dbname={$this->dbname}",
			$this->username, $this->password
		);
	}

	public function close() {
		unset($this->pdo);
	}
	
	public function tableExists(string $table): bool {
		$statement = $this->pdo->prepare("SHOW TABLES LIKE :table");
		$statement->execute(['table' => $table]);
		return $statement->fetchColumn() !== false;
	}
	
	public function runMigrations() {
		
	}
}

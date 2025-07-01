<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Configuration\Configuration;

class Installer extends ConsoleApplication
{
	public function __construct() {
		parent::__construct();
	}
	
	public function run(): void {
		// Check if the configuration file exists.
		if (!file_exists(IZZY_CONFIG_XML) || !is_readable(IZZY_CONFIG_XML)) {
			die("Could not load configuration file: " . IZZY_CONFIG_XML . PHP_EOL);
		}
		
		// Load the configuration file.
		$configuration = new Configuration(IZZY_CONFIG_XML);

		// Connect to the database.
		$db = $configuration->openDatabase();
		$status = $db->connect();
		if (!$status) {
			$errorMessage = $db->getErrorMessage();
			die("Failed to connect to the database" . PHP_EOL . $errorMessage . PHP_EOL);
		}

		// Apply the migrations.
		$manager = $db->migrationManager();
		$manager->runMigrations();
	}
}

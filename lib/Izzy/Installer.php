<?php

namespace Izzy;

use Izzy\Configuration\Configuration;

class Installer extends ConsoleApplication
{
	public function __construct() {
		parent::__construct('analyzer');
	}
	
	public function run() {
		// Check if the configuration file exists.
		if (!file_exists(IZZY_CONFIG_XML) || !is_readable(IZZY_CONFIG_XML)) {
			die("Could not load configuration file: " . IZZY_CONFIG_XML);
		}
		
		// Load the configuration file.
		$configuration = new Configuration(IZZY_CONFIG_XML);

		// Connect to the database.
		$db = $configuration->openDatabase();
		$db->connect();

		// Apply the migrations. 
		$db->runMigrations();
	}
}

<?php

namespace Izzy\AbstractApplications;

use Izzy\Configuration\Configuration;
use Izzy\System\Database;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Base class for all web applications.
 */
abstract class WebApplication
{
	protected App $slimApp;

	protected Configuration $configuration;

	protected Database $database;
	
	public function __construct() {
		$this->slimApp = AppFactory::create();

		// Load the configuration.
		$this->configuration = Configuration::getInstance();

		// Connect to the database.
		$this->database = $this->configuration->openDatabase();
		$this->database->connect();
	}
	
	public function run(): void {
		$this->slimApp->run();
	}
}

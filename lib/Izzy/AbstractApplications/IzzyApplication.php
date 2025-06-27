<?php

namespace Izzy\AbstractApplications;

use Izzy\Configuration\Configuration;
use Izzy\System\Database\Database;
use Izzy\System\Logger;
use Izzy\Traits\SingletonTrait;

abstract class IzzyApplication
{
	use SingletonTrait;

	/**
	 * System logger.
	 * @var Logger 
	 */
	protected Logger $logger;

	/**
	 * Common bot configuration.
	 * @var Configuration 
	 */
	protected Configuration $configuration;

	/**
	 * Database.
	 * @var Database 
	 */
	protected Database $database;
	
	public function __construct() {
		// Load the configuration.
		$this->configuration = Configuration::getInstance();

		// Connect to the database.
		$this->database = $this->configuration->openDatabase();
		$this->database->connect();
		
		// Set up logger.
		$this->logger = Logger::getLogger();
	}

	public function getDatabase(): Database {
		return $this->database;
	}
}

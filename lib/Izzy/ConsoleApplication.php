<?php

namespace Izzy;

use Izzy\Configuration\Configuration;
use Izzy\Traits\SingletonTrait;

/**
 * Base class for all CLI applications.
 */
class ConsoleApplication
{
	use SingletonTrait;

	private string $applicationName;
	protected Logger $logger;
	protected Configuration $configuration;
	protected Database $database;

	public function __construct($applicationName) {
		$this->applicationName = $applicationName;
		$this->logger = Logger::getLogger();

		// Load the configuration.
		$this->configuration = new Configuration(IZZY_CONFIG . "/config.xml");

		// Connect to the database.
		$this->database = $this->configuration->openDatabase();
		$this->database->connect();
	}

	public function getDatabase(): Database {
		return $this->database;
	}
}

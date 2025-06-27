<?php

namespace Izzy\AbstractApplications;

use Izzy\Configuration\Configuration;
use Izzy\System\Database;
use Izzy\System\Logger;
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
	public Database $database;

	public function __construct($applicationName) {
		$this->applicationName = $applicationName;
		$this->logger = Logger::getLogger();

		// Load the configuration.
		$this->configuration = Configuration::getInstance();

		// Connect to the database.
		$this->database = $this->configuration->openDatabase();
		$this->database->connect();
	}
}

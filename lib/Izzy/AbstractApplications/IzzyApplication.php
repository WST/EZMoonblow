<?php

namespace Izzy\AbstractApplications;

use Izzy\Configuration\Configuration;
use Izzy\System\Database\Database;
use Izzy\System\Logger;
use Izzy\Traits\SingletonTrait;

abstract class IzzyApplication {
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
		$status = $this->database->connect();
		if (!$status) {
			$errorMessage = $this->database->getErrorMessage();
			die("Failed to connect to the database".PHP_EOL.$errorMessage.PHP_EOL);
		}

		// Set up logger.
		$this->logger = Logger::getLogger();
	}

	public function getDatabase(): Database {
		return $this->database;
	}

	public function getLogger(): Logger {
		return $this->logger;
	}

	public function getConfiguration(): Configuration {
		return $this->configuration;
	}

	/**
	 * Process all tasks assigned to this Application.
	 */
	protected function processTasks(): void {
		if (!is_callable([$this, 'processTask']))
			return;
		$this->logger->info("Processing scheduled tasks for ".static::getApplicationName());
		$tasks = $this->database->getTasksByApp(static::getApplicationName());
		foreach ($tasks as $task) {
			static::processTask($task);
		}
	}

	public static function getApplicationName(): string {
		return basename(str_replace('\\', '/', static::class));
	}
}

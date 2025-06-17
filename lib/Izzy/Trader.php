<?php

namespace Izzy;

use Izzy\Configuration\Configuration;
use Izzy\Interfaces\IExchangeDriver;

/**
 * Main class of the Trader application.
 * This application is responsible for the actual trading process.
 */
class Trader extends ConsoleApplication
{
	/**
	 * @var IExchangeDriver[] 
	 */
	private array $exchanges;

	/**
	 * Database manager.
	 */
	private Database $database;

	/**
	 * Configuration manager.
	 */
	private Configuration $configuration;

	/**
	 * Builds a Trader object.
	 */
	public function __construct() {
		// Let’s build the parent.
		parent::__construct('trader');
		
		// Load the configuration.
		$this->configuration = new Configuration(IZZY_CONFIG . "/config.xml");
		
		// Connect to the database.
		$this->database = $this->configuration->openDatabase();
		$this->database->connect();
		
		// Finally, let’s load the currently active exchange drivers.
		$this->exchanges = $this->configuration->connectExchanges();
	}

	public function run() {
		// We need to disconnect from the database before splitting.
		$this->database->close();
		unset($this->database);

		// Time to split!
		$status = $this->runExchangeUpdaters();
		die($status);
	}

	/**
	 * Run the exchange updaters.
	 */
	private function runExchangeUpdaters(): int {
		$updaters = array_map(function (IExchangeDriver $exchange) {
			return $exchange->run();
		}, $this->exchanges);

		foreach ($updaters as $updater) {
			$status = NULL;
			pcntl_waitpid($updater, $status);
		}

		return 0;
	}
}

<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Interfaces\IExchangeDriver;
use JetBrains\PhpStorm\NoReturn;

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
	 * Builds a Trader object.
	 */
	public function __construct() {
		// Let’s build the parent.
		parent::__construct();
		
		// Finally, let’s load the currently active exchange drivers.
		$this->exchanges = $this->configuration->connectExchanges($this);
	}

	#[NoReturn]
	public function run(): void {
		// Show console message.
		$this->logger->info('Trader is starting...');
		
		// We need to disconnect from the database before splitting.
		$this->database->close();

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
		
		if (empty($updaters)) {
			$this->logger->warning('No exchanges were found');
		}

		foreach ($updaters as $updater) {
			$status = 0;
			pcntl_waitpid($updater, $status);
		}

		return 0;
	}
}

<?php

namespace Izzy;

use Izzy\Traits\SingletonTrait;

/**
 * Base class for all CLI applications.
 */
class ConsoleApplication
{
	use SingletonTrait;

	private string $applicationName;
	private Logger $logger;

	public function __construct($applicationName) {
		$this->applicationName = $applicationName;
		$this->logger = Logger::getLogger();
	}
}

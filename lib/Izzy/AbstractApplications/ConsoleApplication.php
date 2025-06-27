<?php

namespace Izzy\AbstractApplications;

use Izzy\Configuration\Configuration;
use Izzy\System\Database\Database;
use Izzy\System\Logger;
use Izzy\Traits\SingletonTrait;

/**
 * Base class for all CLI applications.
 */
abstract class ConsoleApplication extends IzzyApplication
{
	private string $applicationName;
	
	public function __construct($applicationName) {
		$this->applicationName = $applicationName;
		parent::__construct();
	}
	
	public function getApplicationName(): string {
		return $this->applicationName;
	}
}

<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Configuration\Configuration;

class Backtester extends ConsoleApplication
{
	public function __construct() {
		parent::__construct();
	}
	
	public function getConfiguration(): Configuration {
		return $this->configuration;
	}
	
	public function run(): void {
		echo "OK" . PHP_EOL;
	}
}

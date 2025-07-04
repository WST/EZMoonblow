<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;

class Backtester extends ConsoleApplication
{
	public function __construct() {
		parent::__construct();
	}
	
	public function run(): void {
		echo "OK" . PHP_EOL;
	}
}

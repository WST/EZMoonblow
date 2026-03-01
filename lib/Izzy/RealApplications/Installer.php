<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\AbstractConsoleApplication;

class Installer extends AbstractConsoleApplication
{
	public function __construct() {
		parent::__construct();
	}

	public function run(): void {
		// Apply the migrations.
		$manager = $this->database->migrationManager();
		$manager->runMigrations();
	}
}

<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;

class Installer extends ConsoleApplication {
	public function __construct() {
		parent::__construct();
	}

	public function run(): void {
		// Apply the migrations.
		$manager = $this->database->migrationManager();
		$manager->runMigrations();
	}
}

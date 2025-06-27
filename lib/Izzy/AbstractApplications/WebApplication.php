<?php

namespace Izzy\AbstractApplications;

use Izzy\Configuration\Configuration;
use Izzy\System\Database\Database;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Base class for all web applications.
 */
abstract class WebApplication extends IzzyApplication
{
	protected App $slimApp;

	protected Configuration $configuration;

	protected Database $database;
	
	public function __construct() {
		$this->slimApp = AppFactory::create();
		parent::__construct();
	}
	
	public function run(): void {
		$this->slimApp->run();
	}
}

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
	public function __construct() {
		parent::__construct();
	}
}

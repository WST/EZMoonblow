<?php

namespace Izzy\AbstractApplications;

/**
 * Base class for all CLI applications.
 */
abstract class ConsoleApplication extends IzzyApplication
{
	public function __construct() {
		parent::__construct();
	}
}

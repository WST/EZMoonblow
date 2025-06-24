<?php

namespace Izzy\AbstractApplications;

/**
 * Base class for all web applications.
 */
class WebApplication
{
	public function __construct() {
		
	}
	
	public static function getInstance(): static {
		return new self();
	}
}

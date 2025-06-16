<?php

namespace Izzy;

class Notifier extends ConsoleApplication
{
	public function __construct() {
		parent::__construct('notifier');
	}

	public static function getInstance(): Notifier {
		static $instance = null;
		if(is_null($instance)) {
			$instance = new self;
		}
		return $instance;
	}

	public function run() {

	}
}
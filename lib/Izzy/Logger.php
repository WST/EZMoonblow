<?php

namespace Izzy;

final class Logger
{
	public function info(string $message): void {
		echo "$message\n";
	}
	
	public static function getLogger(): self {
		static $logger;
		if (!is_null($logger)) {
			return $logger;
		}
		$logger = new self();
		return $logger;
	}
}

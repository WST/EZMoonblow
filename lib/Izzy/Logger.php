<?php

namespace Izzy;

final class Logger
{
	public function info(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		echo "\033[32m$timestamp\033[0m $message\n";
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

<?php

namespace Izzy;

final class Logger
{
	public function info(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		echo "\033[32m$timestamp\033[0m Info: $message\n";
	}
	
	public function warning(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		echo "\033[32m$timestamp\033[0m Warning: $message\n";
	}

	public function error(string $string): void {
		$timestamp = date('Y-m-d H:i:s');
		echo "\033[31m$timestamp\033[0m Error: $string\n";
	}
	
	public function debug(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		echo "\033[32m$timestamp\033[0m Debug: $message\n";
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

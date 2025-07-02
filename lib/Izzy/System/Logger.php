<?php

namespace Izzy\System;

final class Logger
{
	private bool $isWebApp = false;
	private string $logFile = '';
	
	public function __construct() {
		// Определяем, запущено ли приложение как веб-приложение
		$this->isWebApp = php_sapi_name() !== 'cli' || isset($_SERVER['HTTP_HOST']);
		
		if ($this->isWebApp) {
			// Для веб-приложений логируем в файл
			$this->logFile = IZZY_ROOT . '/logs/web.log';
			$logDir = dirname($this->logFile);
			if (!is_dir($logDir)) {
				mkdir($logDir, 0755, true);
			}
		}
	}
	
	public function info(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "$timestamp Info: $message";
		
		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[32m$logMessage\033[0m\n";
		}
	}
	
	public function warning(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "$timestamp Warning: $message";
		
		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[33m$logMessage\033[0m\n";
		}
	}

	public function error(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "$timestamp Error: $message";
		
		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[31m$logMessage\033[0m\n";
		}
	}
	
	public function debug(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "$timestamp Debug: $message";
		
		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[36m$logMessage\033[0m\n";
		}
	}
	
	private function writeToFile(string $message): void {
		if ($this->logFile) {
			file_put_contents($this->logFile, $message . "\n", FILE_APPEND | LOCK_EX);
		}
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

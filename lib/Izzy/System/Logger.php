<?php

namespace Izzy\System;

/**
 * Logger class for application-wide logging.
 * Supports both console (CLI) and web application logging modes.
 */
final class Logger
{
	/** @var bool Whether the application is running as a web application. */
	private bool $isWebApp = false;

	/** @var string Path to the log file for web applications. */
	private string $logFile = '';

	/** @var bool When true, debug and info are suppressed (used during backtest to reduce noise). */
	private bool $backtestMode = false;

	/** @var int When > 0, backtestProgress uses this instead of real wallclock time. */
	private int $backtestSimulationTime = 0;

	/**
	 * Enable or disable backtest mode. In backtest mode, debug() and info() are no-ops;
	 * only warning, error and fatal are logged. Use when running backtests to avoid live-trading noise.
	 * @param bool $on Whether to enable backtest mode.
	 * @return void
	 */
	public function setBacktestMode(bool $on): void {
		$this->backtestMode = $on;
		if (!$on) {
			$this->backtestSimulationTime = 0;
		}
	}

	/**
	 * Check if the logger is in backtest mode.
	 * Useful for skipping side effects (e.g. Telegram notifications) during backtests.
	 * @return bool True if backtest mode is enabled.
	 */
	public function isBacktestMode(): bool {
		return $this->backtestMode;
	}

	/**
	 * Set the simulation timestamp for backtest log lines.
	 * @param int $timestamp Unix timestamp from the simulated candle.
	 */
	public function setBacktestSimulationTime(int $timestamp): void {
		$this->backtestSimulationTime = $timestamp;
	}

	/**
	 * Log a message only when in backtest mode. Use for backtest progress (candle index, balance, open/close).
	 * No-op when not in backtest mode.
	 * @param string $message Message to log.
	 * @return void
	 */
	public function backtestProgress(string $message): void {
		if (!$this->backtestMode) {
			return;
		}
		$timestamp = $this->backtestSimulationTime > 0
			? date('Y-m-d H:i:s', $this->backtestSimulationTime)
			: date('Y-m-d H:i:s');
		$logMessage = "$timestamp [Backtest] $message";
		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[90m$logMessage\033[0m\n";
		}
	}

	/**
	 * Initialize the logger.
	 * Determines if the application is running as a web application and sets up logging accordingly.
	 */
	public function __construct() {
		// Determine if the application is running as a web application.
		$this->isWebApp = php_sapi_name() !== 'cli' || isset($_SERVER['HTTP_HOST']);

		if ($this->isWebApp) {
			// For web applications, log to a file.
			$this->logFile = IZZY_ROOT . '/logs/web.log';
			$logDir = dirname($this->logFile);
			if (!is_dir($logDir)) {
				mkdir($logDir, 0755, true);
			}
		}
	}

	/**
	 * Log an informational message.
	 * @param string $message Message to log.
	 * @return void
	 */
	public function info(string $message): void {
		if ($this->backtestMode) {
			return;
		}
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "$timestamp Info: $message";

		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[32m$logMessage\033[0m\n";
		}
	}

	/**
	 * Log a warning message.
	 * @param string $message Warning message to log.
	 * @return void
	 */
	public function warning(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "$timestamp Warning: $message";

		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[33m$logMessage\033[0m\n";
		}
	}

	/**
	 * Log an error message.
	 * @param string $message Error message to log.
	 * @return void
	 */
	public function error(string $message): void {
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "$timestamp Error: $message";

		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[31m$logMessage\033[0m\n";
		}
	}

	/**
	 * Log a fatal error and terminate the process.
	 * @param string $message Fatal error message.
	 * @param int $exitCode Exit code to use when terminating (default: 1).
	 * @return void
	 */
	public function fatal(string $message, int $exitCode = 1): void {
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "$timestamp Fatal: $message";

		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[31m$logMessage\033[0m\n";
		}

		die($exitCode);
	}

	/**
	 * Log a debug message.
	 * @param string $message Debug message to log.
	 * @return void
	 */
	public function debug(string $message): void {
		if ($this->backtestMode) {
			return;
		}
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "$timestamp Debug: $message";

		if ($this->isWebApp) {
			$this->writeToFile($logMessage);
		} else {
			echo "\033[36m$logMessage\033[0m\n";
		}
	}

	/**
	 * Write a log message to the log file.
	 * @param string $message Message to write to the file.
	 * @return void
	 */
	private function writeToFile(string $message): void {
		if ($this->logFile) {
			file_put_contents($this->logFile, $message."\n", FILE_APPEND | LOCK_EX);
		}
	}

	// ------------------------------------------------------------------
	// Slow query log
	// ------------------------------------------------------------------

	/** Threshold in milliseconds. Queries slower than this are logged. */
	private const float SLOW_QUERY_THRESHOLD_MS = 50.0;

	/** @var string|null Resolved path to the slow query log file (lazy-initialized). */
	private ?string $slowQueryLogFile = null;

	/** @var int Total number of queries executed since the process started. */
	private int $queryCount = 0;

	/** @var float Total time spent in database queries (milliseconds). */
	private float $queryTotalMs = 0.0;

	/**
	 * Log a database query if it exceeds the slow query threshold.
	 * Always accumulates stats (query count and total time) regardless of threshold.
	 * Writes strictly to a file — never to stdout — to avoid polluting CLI output.
	 *
	 * @param string $sql  The SQL query text (or a summary of it).
	 * @param float  $ms   Execution time in milliseconds.
	 */
	public function logQuery(string $sql, float $ms): void {
		$this->queryCount++;
		$this->queryTotalMs += $ms;

		if ($ms < self::SLOW_QUERY_THRESHOLD_MS) {
			return;
		}

		// Lazy-init the log file path.
		if ($this->slowQueryLogFile === null) {
			$logDir = IZZY_ROOT . '/logs';
			if (!is_dir($logDir)) {
				mkdir($logDir, 0755, true);
			}
			$this->slowQueryLogFile = $logDir . '/slow-queries.log';
		}

		$timestamp = date('Y-m-d H:i:s');
		$msFormatted = number_format($ms, 1);
		// Truncate very long queries to keep the log readable.
		$sqlTruncated = mb_strlen($sql) > 500 ? mb_substr($sql, 0, 500) . '...' : $sql;
		$line = "$timestamp [{$msFormatted}ms] $sqlTruncated";
		file_put_contents($this->slowQueryLogFile, $line . "\n", FILE_APPEND | LOCK_EX);
	}

	/**
	 * Get accumulated query statistics.
	 * Useful for profiling at the end of a run (e.g. backtests).
	 *
	 * @return array{count: int, totalMs: float}
	 */
	public function getQueryStats(): array {
		return [
			'count' => $this->queryCount,
			'totalMs' => $this->queryTotalMs,
		];
	}

	/**
	 * Get the singleton logger instance.
	 * @return self Logger instance.
	 */
	public static function getLogger(): self {
		static $logger;
		if (!is_null($logger)) {
			return $logger;
		}
		$logger = new self();
		return $logger;
	}
}

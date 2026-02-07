<?php

namespace Izzy\AbstractApplications;

use Izzy\System\SystemHeartbeat;

/**
 * Base class for all CLI applications.
 */
abstract class ConsoleApplication extends IzzyApplication
{
	/** @var SystemHeartbeat|null Heartbeat manager for health monitoring. */
	protected ?SystemHeartbeat $heartbeat = null;

	/** @var bool Flag to indicate graceful shutdown requested. */
	protected static bool $shouldStop = false;

	public function __construct() {
		parent::__construct();
	}

	/**
	 * Initialize and start the heartbeat for this component.
	 * Should be called at the beginning of run().
	 */
	protected function startHeartbeat(): void {
		$this->heartbeat = new SystemHeartbeat($this->database, static::getApplicationName());
		$this->heartbeat->start();

		// Register signal handlers for graceful shutdown.
		$this->registerSignalHandlers();
	}

	/**
	 * Register signal handlers for graceful shutdown.
	 * Handles SIGINT (Ctrl+C) and SIGTERM.
	 */
	private function registerSignalHandlers(): void {
		// Enable async signal handling.
		pcntl_async_signals(true);

		$shutdownHandler = function (int $signal): void {
			$signalName = $signal === SIGINT ? 'SIGINT' : 'SIGTERM';
			$this->logger->info("Received $signalName, shutting down gracefully...");
			self::$shouldStop = true;
			$this->stopHeartbeat();
			exit(0);
		};

		pcntl_signal(SIGINT, $shutdownHandler);
		pcntl_signal(SIGTERM, $shutdownHandler);
	}

	/**
	 * Sleep for specified seconds, but check for shutdown signal every second.
	 * This allows for quick response to Ctrl+C even during long sleep intervals.
	 *
	 * @param int $seconds Number of seconds to sleep.
	 */
	protected function interruptibleSleep(int $seconds): void {
		for ($i = 0; $i < $seconds && !self::$shouldStop; $i++) {
			sleep(1);
		}
	}

	/**
	 * Update the heartbeat timestamp.
	 * Should be called regularly in the main loop.
	 *
	 * @param array|null $extraInfo Optional extra information to store.
	 */
	protected function beat(?array $extraInfo = null): void {
		$this->heartbeat?->beat($extraInfo);
	}

	/**
	 * Mark the component as stopped.
	 * Should be called when shutting down gracefully.
	 */
	protected function stopHeartbeat(): void {
		$this->heartbeat?->stop();
	}
}

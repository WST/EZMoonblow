<?php

namespace Izzy\AbstractApplications;

use Izzy\System\SystemHeartbeat;

/**
 * Base class for all CLI applications.
 */
abstract class ConsoleApplication extends IzzyApplication {
	/** @var SystemHeartbeat|null Heartbeat manager for health monitoring. */
	protected ?SystemHeartbeat $heartbeat = null;

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
	}

	/**
	 * Update the heartbeat timestamp.
	 * Should be called regularly in the main loop.
	 *
	 * @param array|null $extraInfo Optional extra information to store.
	 */
	protected function beat(?array $extraInfo = null): void {
		if ($this->heartbeat) {
			$this->heartbeat->beat($extraInfo);
		}
	}

	/**
	 * Mark the component as stopped.
	 * Should be called when shutting down gracefully.
	 */
	protected function stopHeartbeat(): void {
		if ($this->heartbeat) {
			$this->heartbeat->stop();
		}
	}
}

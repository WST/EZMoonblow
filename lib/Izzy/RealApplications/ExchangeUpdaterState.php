<?php

namespace Izzy\RealApplications;

use Izzy\Interfaces\IExchangeDriver;

/**
 * Runtime state of a supervised exchange updater child process.
 */
final class ExchangeUpdaterState
{
	public readonly string $exchangeName;
	public readonly IExchangeDriver $driver;
	public ?int $pid = null;
	public bool $isEnabled = true;
	public int $restartDelaySec;
	public int $scheduledDelaySec = 0;
	public int $nextRestartAt = 0;

	public function __construct(string $exchangeName, IExchangeDriver $driver, int $initialRestartDelaySec) {
		$this->exchangeName = $exchangeName;
		$this->driver = $driver;
		$this->restartDelaySec = $initialRestartDelaySec;
	}

	public function markStarted(int $pid): void {
		$this->pid = $pid;
		$this->scheduledDelaySec = 0;
		$this->nextRestartAt = 0;
	}

	public function markStopped(): void {
		$this->pid = null;
	}

	public function disable(): void {
		$this->isEnabled = false;
		$this->scheduledDelaySec = 0;
		$this->nextRestartAt = 0;
	}

	public function scheduleRestart(int $maxRestartDelaySec, int $now): int {
		$delay = $this->restartDelaySec;
		$this->scheduledDelaySec = $delay;
		$this->nextRestartAt = $now + $delay;
		$this->restartDelaySec = min($delay * 2, $maxRestartDelaySec);
		return $delay;
	}

	public function hasActiveOrPendingWork(): bool {
		return $this->pid !== null || ($this->isEnabled && $this->nextRestartAt > 0);
	}

	public function shouldRestartAt(int $currentTime): bool {
		return $this->isEnabled && $this->pid === null && $this->nextRestartAt > 0 && $this->nextRestartAt <= $currentTime;
	}
}


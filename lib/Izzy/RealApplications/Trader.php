<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Interfaces\IExchangeDriver;

/**
 * Main class of the Trader application.
 * This application is responsible for the actual trading process.
 */
class Trader extends ConsoleApplication
{
	private const int RESTART_DELAY_INITIAL_SEC = 1;
	private const int RESTART_DELAY_MAX_SEC = 3600;
	private const int EMPTY_EXIT_CODE = 0;
	private const string REASON_START_FAILED = 'failed to start child process';
	private const string REASON_UNKNOWN = 'unknown termination reason';

	/**
	 * @var IExchangeDriver[]
	 */
	private array $exchanges;

	/**
	 * Builds a Trader object.
	 */
	public function __construct() {
		// Let's build the parent.
		parent::__construct();

		// Finally, let’s load the currently active exchange drivers.
		$this->exchanges = $this->configuration->connectExchanges($this);
	}

	public function run(): void {
		// Show console message.
		$this->logger->info('Trader is starting...');

		// We need to disconnect from the database before splitting.
		$this->database->close();

		// Time to split!
		$status = $this->runExchangeUpdaters();
		die($status);
	}

	/**
	 * Run the exchange updaters.
	 */
	private function runExchangeUpdaters(): int {
		if (empty($this->exchanges)) {
			$this->logger->warning('No exchanges were found');
			return self::EMPTY_EXIT_CODE;
		}

		// Supervisor lifecycle flags:
		// - $shouldStop means shutdown has been requested.
		// - $shuttingDown prevents repeated SIGTERM broadcasts.
		$shouldStop = false;
		$shuttingDown = false;
		pcntl_async_signals(true);
		$shutdownHandler = function (int $signal) use (&$shouldStop): void {
			$signalName = $signal === SIGINT ? 'SIGINT' : 'SIGTERM';
			$this->logger->warning("Trader supervisor received $signalName and is shutting down child processes");
			$shouldStop = true;
		};
		pcntl_signal(SIGINT, $shutdownHandler);
		pcntl_signal(SIGTERM, $shutdownHandler);

		/** @var ExchangeUpdaterState[] $processes */
		$processes = [];
		foreach ($this->exchanges as $exchangeName => $driver) {
			$processes[$exchangeName] = new ExchangeUpdaterState(
				$exchangeName,
				$driver,
				self::RESTART_DELAY_INITIAL_SEC
			);
		}

		// Reverse lookup from child PID to exchange name.
		$pidToExchange = [];
		// Number of currently running child updater processes.
		$activeChildren = 0;
		$scheduleRestart = function (string $exchangeName, string $reason) use (&$processes): void {
			$state = $processes[$exchangeName];
			$delay = $state->scheduleRestart(self::RESTART_DELAY_MAX_SEC, time());
			$this->logger->warning("Updater for exchange $exchangeName ended abnormally ($reason). Restart is scheduled in $delay second(s)");
		};
		$startUpdater = function (string $exchangeName, bool $isRestart = false) use (&$processes, &$pidToExchange, &$activeChildren, $scheduleRestart): void {
			// On restart, we log the cooldown that has already been waited.
			$state = $processes[$exchangeName];
			$scheduledDelay = $state->scheduledDelaySec;
			if ($isRestart && $scheduledDelay > 0) {
				$this->logger->info("Restarting updater for exchange $exchangeName after $scheduledDelay second(s) cooldown");
			}

			$pid = $state->driver->run();
			if ($pid > 0) {
				$state->markStarted($pid);
				$pidToExchange[$pid] = $exchangeName;
				$activeChildren++;
				return;
			}

			$state->markStopped();
			$scheduleRestart($exchangeName, self::REASON_START_FAILED);
		};
		$hasActiveOrPendingWork = function () use (&$processes): bool {
			// The supervisor exits only when there are no running children
			// and no scheduled restarts for enabled exchanges.
			return array_any($processes, fn($state) => $state->hasActiveOrPendingWork());
		};

		foreach (array_keys($processes) as $exchangeName) {
			$startUpdater($exchangeName);
		}

		while (true) {
			// Drain all finished children without blocking.
			while (true) {
				$status = 0;
				$pid = pcntl_waitpid(-1, $status, WNOHANG);
				if ($pid <= 0) {
					break;
				}

				if (!isset($pidToExchange[$pid])) {
					$this->logger->warning("Unknown child process $pid has finished");
					continue;
				}

				$exchangeName = $pidToExchange[$pid];
				unset($pidToExchange[$pid]);
				$state = $processes[$exchangeName];
				$state->markStopped();
				$activeChildren = max(0, $activeChildren - 1);

				if ($shouldStop) {
					continue;
				}

				// Restart policy:
				// - exit 0 => keep updater disabled,
				// - any non-zero exit or signal => schedule restart with backoff.
				if (pcntl_wifexited($status)) {
					$childExitCode = pcntl_wexitstatus($status);
					if ($childExitCode === self::EMPTY_EXIT_CODE) {
						$state->disable();
						$this->logger->warning("Updater for exchange $exchangeName exited with code 0 and will stay disabled");
					} else {
						$scheduleRestart($exchangeName, "exit code $childExitCode");
					}
					continue;
				}

				if (pcntl_wifsignaled($status)) {
					$signal = pcntl_wtermsig($status);
					$scheduleRestart($exchangeName, "signal $signal");
					continue;
				}

				$scheduleRestart($exchangeName, self::REASON_UNKNOWN);
			}

			// First shutdown iteration: ask all active children to stop gracefully.
			if ($shouldStop && !$shuttingDown) {
				foreach ($processes as $exchangeName => $state) {
					if ($state->pid === null) {
						continue;
					}
					$this->logger->info("Sending SIGTERM to updater for exchange $exchangeName (pid {$state->pid})");
					posix_kill($state->pid, SIGTERM);
				}
				$shuttingDown = true;
			}

			// Start only updaters whose scheduled restart time has arrived.
			if (!$shouldStop) {
				$currentTime = time();
				foreach ($processes as $exchangeName => $state) {
					if (!$state->shouldRestartAt($currentTime)) {
						continue;
					}
					$startUpdater($exchangeName, true);
				}
			}

			// Shutdown completed: all children are gone.
			if ($shouldStop && $activeChildren === 0) {
				return self::EMPTY_EXIT_CODE;
			}

			// Normal completion: nothing is running and nothing is scheduled.
			if (!$shouldStop && !$hasActiveOrPendingWork()) {
				return self::EMPTY_EXIT_CODE;
			}

			// Keep the supervisor loop cheap when there are no events.
			sleep(1);
		}
	}
}

<?php

namespace Izzy\System;

use Izzy\System\Database\Database;

/**
 * System heartbeat manager for monitoring component health.
 *
 * Components (Trader, Analyzer, Notifier) should call beat() regularly
 * to indicate they are running. The web interface can then check
 * heartbeat timestamps to determine component health status.
 */
class SystemHeartbeat {
	/** @var Database Database connection instance. */
	private Database $database;

	/** @var string Component name (Trader, Analyzer, Notifier). */
	private string $componentName;

	/** @var int|null Process ID. */
	private ?int $pid;

	/** @var int|null Unix timestamp when the component started. */
	private ?int $startedAt;

	/** Table name for heartbeat records. */
	private const TABLE_NAME = 'system_heartbeats';

	/** Column names. */
	const FComponentName = 'heartbeat_component_name';
	const FLastHeartbeat = 'heartbeat_last_heartbeat';
	const FStatus = 'heartbeat_status';
	const FPid = 'heartbeat_pid';
	const FStartedAt = 'heartbeat_started_at';
	const FExtraInfo = 'heartbeat_extra_info';

	/** Status constants. */
	public const STATUS_RUNNING = 'Running';
	public const STATUS_STOPPED = 'Stopped';
	public const STATUS_ERROR = 'Error';

	/** Health thresholds in seconds. */
	public const THRESHOLD_WARNING = 90;   // > max sleep interval (60s) + execution time
	public const THRESHOLD_CRITICAL = 180; // 3 minutes without heartbeat

	/**
	 * Create a new SystemHeartbeat instance.
	 *
	 * @param Database $database Database connection.
	 * @param string $componentName Name of the component.
	 */
	public function __construct(Database $database, string $componentName) {
		$this->database = $database;
		$this->componentName = $componentName;
		$this->pid = getmypid() ?: null;
		$this->startedAt = time();
	}

	/**
	 * Initialize the heartbeat record for this component.
	 * Should be called once when the component starts.
	 */
	public function start(): void {
		$existingRecord = $this->database->selectOneRow(
			self::TABLE_NAME,
			'*',
			[self::FComponentName => $this->componentName]
		);

		$data = [
			self::FLastHeartbeat => time(),
			self::FStatus => self::STATUS_RUNNING,
			self::FPid => $this->pid,
			self::FStartedAt => $this->startedAt,
			self::FExtraInfo => null,
		];

		if ($existingRecord) {
			$this->database->update(
				self::TABLE_NAME,
				$data,
				[self::FComponentName => $this->componentName]
			);
		} else {
			$data[self::FComponentName] = $this->componentName;
			$this->database->insert(self::TABLE_NAME, $data);
		}
	}

	/**
	 * Update the heartbeat timestamp.
	 * Should be called regularly in the main loop.
	 *
	 * @param array|null $extraInfo Optional extra information to store as JSON.
	 */
	public function beat(?array $extraInfo = null): void {
		$data = [
			self::FLastHeartbeat => time(),
			self::FStatus => self::STATUS_RUNNING,
		];

		if ($extraInfo !== null) {
			$data[self::FExtraInfo] = json_encode($extraInfo);
		}

		$this->database->update(
			self::TABLE_NAME,
			$data,
			[self::FComponentName => $this->componentName]
		);
	}

	/**
	 * Mark the component as stopped.
	 * Should be called when the component shuts down gracefully.
	 */
	public function stop(): void {
		$this->database->update(
			self::TABLE_NAME,
			[
				self::FStatus => self::STATUS_STOPPED,
				self::FPid => null,
			],
			[self::FComponentName => $this->componentName]
		);
	}

	/**
	 * Mark the component as having an error.
	 *
	 * @param string|null $errorMessage Optional error message to store.
	 */
	public function error(?string $errorMessage = null): void {
		$data = [
			self::FLastHeartbeat => time(),
			self::FStatus => self::STATUS_ERROR,
		];

		if ($errorMessage !== null) {
			$data[self::FExtraInfo] = json_encode(['error' => $errorMessage]);
		}

		$this->database->update(
			self::TABLE_NAME,
			$data,
			[self::FComponentName => $this->componentName]
		);
	}

	/**
	 * Get the status of a specific component.
	 *
	 * @param Database $database Database connection.
	 * @param string $componentName Component name.
	 * @return array|null Component status data or null if not found.
	 */
	public static function getComponentStatus(Database $database, string $componentName): ?array {
		$row = $database->selectOneRow(
			self::TABLE_NAME,
			'*',
			[self::FComponentName => $componentName]
		);

		if (!$row) {
			return null;
		}

		return self::enrichStatusData($row);
	}

	/**
	 * Get the status of all components.
	 *
	 * @param Database $database Database connection.
	 * @return array[] Array of component status data.
	 */
	public static function getAllComponentStatuses(Database $database): array {
		$rows = $database->selectAllRows(self::TABLE_NAME);
		return array_map([self::class, 'enrichStatusData'], $rows);
	}

	/**
	 * Enrich status data with health indicators and formatted values.
	 *
	 * @param array $row Raw database row.
	 * @return array Enriched status data.
	 */
	private static function enrichStatusData(array $row): array {
		$lastHeartbeat = $row[self::FLastHeartbeat] ? (int)$row[self::FLastHeartbeat] : null;
		$startedAt = $row[self::FStartedAt] ? (int)$row[self::FStartedAt] : null;
		$now = time();

		// Calculate seconds since last heartbeat
		$secondsSinceHeartbeat = $lastHeartbeat ? ($now - $lastHeartbeat) : null;

		// Determine health status
		$health = self::calculateHealth($row[self::FStatus], $secondsSinceHeartbeat);

		// Calculate uptime
		$uptime = null;
		if ($startedAt && $row[self::FStatus] === self::STATUS_RUNNING) {
			$uptime = $now - $startedAt;
		}

		// Parse extra info
		$extraInfo = null;
		if (!empty($row[self::FExtraInfo])) {
			$extraInfo = json_decode($row[self::FExtraInfo], true);
		}

		return [
			'component_name' => $row[self::FComponentName],
			'status' => $row[self::FStatus],
			'last_heartbeat' => $lastHeartbeat,
			'last_heartbeat_ago' => $secondsSinceHeartbeat,
			'pid' => $row[self::FPid] ? (int)$row[self::FPid] : null,
			'started_at' => $startedAt,
			'uptime' => $uptime,
			'health' => $health,
			'extra_info' => $extraInfo,
		];
	}

	/**
	 * Calculate health status based on component status and heartbeat age.
	 *
	 * @param string $status Component status.
	 * @param int|null $secondsSinceHeartbeat Seconds since last heartbeat.
	 * @return string Health status: 'healthy', 'warning', 'critical', or 'unknown'.
	 */
	private static function calculateHealth(string $status, ?int $secondsSinceHeartbeat): string {
		// If status is explicitly Error or Stopped
		if ($status === self::STATUS_ERROR) {
			return 'critical';
		}

		if ($status === self::STATUS_STOPPED) {
			return 'stopped';
		}

		// If never had a heartbeat
		if ($secondsSinceHeartbeat === null) {
			return 'unknown';
		}

		// Check heartbeat age
		if ($secondsSinceHeartbeat <= self::THRESHOLD_WARNING) {
			return 'healthy';
		}

		if ($secondsSinceHeartbeat <= self::THRESHOLD_CRITICAL) {
			return 'warning';
		}

		return 'critical';
	}

	/**
	 * Format uptime as human-readable string.
	 *
	 * @param int|null $seconds Uptime in seconds.
	 * @return string Formatted uptime string.
	 */
	public static function formatUptime(?int $seconds): string {
		if ($seconds === null) {
			return '-';
		}

		$days = floor($seconds / 86400);
		$hours = floor(($seconds % 86400) / 3600);
		$minutes = floor(($seconds % 3600) / 60);
		$secs = $seconds % 60;

		$parts = [];
		if ($days > 0) {
			$parts[] = "{$days}d";
		}
		if ($hours > 0) {
			$parts[] = "{$hours}h";
		}
		if ($minutes > 0) {
			$parts[] = "{$minutes}m";
		}
		if (empty($parts) || $secs > 0) {
			$parts[] = "{$secs}s";
		}

		return implode(' ', $parts);
	}

	/**
	 * Format "time ago" as human-readable string.
	 *
	 * @param int|null $seconds Seconds ago.
	 * @return string Formatted string.
	 */
	public static function formatTimeAgo(?int $seconds): string {
		if ($seconds === null) {
			return 'never';
		}

		if ($seconds < 60) {
			return "{$seconds}s ago";
		}

		$minutes = floor($seconds / 60);
		if ($minutes < 60) {
			return "{$minutes}m ago";
		}

		$hours = floor($minutes / 60);
		if ($hours < 24) {
			return "{$hours}h ago";
		}

		$days = floor($hours / 24);
		return "{$days}d ago";
	}
}

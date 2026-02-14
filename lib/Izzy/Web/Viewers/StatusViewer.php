<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Enums\TaskStatusEnum;
use Izzy\System\SystemHeartbeat;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Viewer for the System Status page.
 * Displays health status of system components and task queue statistics.
 */
class StatusViewer extends PageViewer
{
	/** @var array Expected system components. */
	private const EXPECTED_COMPONENTS = ['Trader', 'Analyzer', 'Notifier'];

	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response): Response {
		$database = $this->webApp->getDatabase();

		// Get component statuses.
		$componentStatuses = $this->getComponentStatuses($database);

		// Get task queue statistics.
		$taskStats = $this->getTaskQueueStats($database);

		// Get recent tasks.
		$recentTasks = $this->getRecentTasks($database);

		$body = $this->webApp->getTwig()->render('status.htt', [
			'menu' => $this->menu,
			'components' => $componentStatuses,
			'taskStats' => $taskStats,
			'recentTasks' => $recentTasks,
			'thresholdWarning' => SystemHeartbeat::THRESHOLD_WARNING,
			'thresholdCritical' => SystemHeartbeat::THRESHOLD_CRITICAL,
		]);

		$response->getBody()->write($body);
		return $response;
	}

	/**
	 * Get component statuses with defaults for missing components.
	 *
	 * @param mixed $database Database connection.
	 * @return array Component status data.
	 */
	private function getComponentStatuses($database): array {
		$statuses = SystemHeartbeat::getAllComponentStatuses($database);

		// Index by component name for easy lookup.
		$statusMap = [];
		foreach ($statuses as $status) {
			$statusMap[$status['component_name']] = $status;
		}

		// Ensure all expected components are present.
		$result = [];
		foreach (self::EXPECTED_COMPONENTS as $componentName) {
			if (isset($statusMap[$componentName])) {
				$status = $statusMap[$componentName];
				$status['uptime_formatted'] = SystemHeartbeat::formatUptime($status['uptime']);
				$status['last_heartbeat_formatted'] = SystemHeartbeat::formatTimeAgo($status['last_heartbeat_ago']);
				$result[] = $status;
			} else {
				// Component has never been started.
				$result[] = [
					'component_name' => $componentName,
					'status' => 'Never Started',
					'last_heartbeat' => null,
					'last_heartbeat_ago' => null,
					'last_heartbeat_formatted' => 'never',
					'pid' => null,
					'started_at' => null,
					'uptime' => null,
					'uptime_formatted' => '-',
					'health' => 'unknown',
					'extra_info' => null,
					'memory_usage' => null,
					'memory_usage_formatted' => '-',
				];
			}
		}

		return $result;
	}

	/**
	 * Get task queue statistics.
	 *
	 * @param mixed $database Database connection.
	 * @return array Task statistics.
	 */
	private function getTaskQueueStats($database): array {
		$stats = [
			'total' => 0,
			'pending' => 0,
			'in_progress' => 0,
			'completed' => 0,
			'failed' => 0,
		];

		// Count tasks by status.
		$rows = $database->selectAllRows('tasks');
		foreach ($rows as $row) {
			$stats['total']++;
			$status = $row['task_status'];

			switch ($status) {
				case TaskStatusEnum::PENDING->value:
					$stats['pending']++;
					break;
				case TaskStatusEnum::INPROGRESS->value:
					$stats['in_progress']++;
					break;
				case TaskStatusEnum::COMPLETED->value:
					$stats['completed']++;
					break;
				case TaskStatusEnum::FAILED->value:
					$stats['failed']++;
					break;
			}
		}

		return $stats;
	}

	/**
	 * Get recent tasks for display.
	 *
	 * @param mixed $database Database connection.
	 * @param int $limit Maximum number of tasks to return.
	 * @return array Recent tasks.
	 */
	private function getRecentTasks($database, int $limit = 10): array {
		$rows = $database->selectAllRows('tasks', '*', [], 'task_id DESC', $limit);

		$tasks = [];
		foreach ($rows as $row) {
			$createdAt = (int)$row['task_created_at'];
			$attributes = json_decode($row['task_attributes'], true) ?: [];

			$tasks[] = [
				'id' => $row['task_id'],
				'sender' => $row['task_sender'] ?? null,
				'recipient' => $row['task_recipient'],
				'type' => $row['task_type'],
				'status' => $row['task_status'],
				'created_at' => $createdAt,
				'created_ago' => SystemHeartbeat::formatTimeAgo(time() - $createdAt),
				'attributes' => $attributes,
			];
		}

		return $tasks;
	}
}

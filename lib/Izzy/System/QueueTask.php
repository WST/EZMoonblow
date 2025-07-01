<?php

namespace Izzy\System;

use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\TaskRecipientEnum;
use Izzy\Enums\TaskStatusEnum;
use Izzy\Enums\TaskTypeEnum;
use Izzy\Financial\Market;
use Izzy\RealApplications\Analyzer;
use Izzy\RealApplications\Notifier;
use Izzy\System\Database\Database;
use Izzy\System\Database\ORM\SurrogatePKDatabaseRecord;

class QueueTask extends SurrogatePKDatabaseRecord
{
	const FId = 'task_id';
	const FRecipient = 'task_recipient';
	const FType = 'task_type';
	const FStatus = 'task_status';
	const FCreatedAt = 'task_created_at';
	const FAttributes = 'task_attributes';
	
	public function __construct(Database $database, array $row) {
		parent::__construct($database, $row, self::FId);
	}

	/**
	 * Name of the database table to store queue tasks.
	 * @return string
	 */
	public static function getTableName(): string {
		return 'tasks';
	}
	
	public static function addTelegramNotification_newPosition(Market $sender, PositionDirectionEnum $direction): void {
		$database = $sender->getDatabase();
		$appName = Notifier::getApplicationName();

		// Check if such a task already exists for this market.
		if (self::taskAlreadyExists($database, $sender, $appName, TaskTypeEnum::TELEGRAM_WANT_NEW_POSITION)) {
			return;
		}
		
		// New attributes.
		$attributes = $sender->getTaskMarketAttributes();
		$attributes['direction'] = $direction->value;

		// New row.
		$row = [
			self::FCreatedAt => time(),
			self::FAttributes => json_encode($attributes),
			self::FRecipient => $appName,
			self::FType => TaskTypeEnum::TELEGRAM_WANT_NEW_POSITION->value,
			self::FStatus => TaskStatusEnum::PENDING->value,
		];

		// New task.
		$task = new self($database, $row);

		// Saving the newly created task.
		$task->save();
	}

	public static function updateChart(Market $sender): void {
		$database = $sender->getDatabase();
		$appName = Analyzer::getApplicationName();
		
		// Check if such a task already exists for this market.
		if (self::taskAlreadyExists($database, $sender, $appName, TaskTypeEnum::DRAW_CANDLESTICK_CHART)) {
			return;
		}
		
		// New attributes.
		$attributes = $sender->getTaskMarketAttributes();
		
		// New row.
		$row = [
			self::FCreatedAt => time(),
			self::FAttributes => json_encode($attributes),
			self::FRecipient => $appName,
			self::FType => TaskTypeEnum::DRAW_CANDLESTICK_CHART->value,
			self::FStatus => TaskStatusEnum::PENDING->value,
		];
		
		// New task.
		$task = new self($database, $row);
		
		// Saving the newly created task.
		$task->save();
	}

	/**
	 * Checks whether a task of given type already exists for such Market (based on attributes).
	 * @param Database $database
	 * @param Market $sender
	 * @param string $appName
	 * @param TaskTypeEnum $taskType
	 * @return bool
	 */
	private static function taskAlreadyExists(
		Database $database,
		Market $sender,
		string $appName,
		TaskTypeEnum $taskType
	): bool {
		$existingTasks = $database->selectAllRows(
			QueueTask::getTableName(),
			'*',
			[self::FRecipient => $appName, self::FType => $taskType->value],
		);

		// If the attributes match, the task already exists then.
		foreach ($existingTasks as $task) {
			$taskAttributes = json_decode($task[self::FAttributes], true);
			if ($sender->taskMarketAttributesMatch($taskAttributes)) {
				return true;
			}
		}
		
		return false;
	}

	public function getStatus(): TaskStatusEnum {
		return TaskStatusEnum::from($this->row[self::FStatus]);
	}
	
	public function getRecipient(): TaskRecipientEnum {
		return TaskRecipientEnum::from($this->row[self::FRecipient]);
	}
	
	public function getType(): TaskTypeEnum {
		return TaskTypeEnum::from($this->row[self::FType]);
	}

	public function getAttributes(): array {
		return json_decode($this->row[self::FAttributes], true);
	}

	public function setAttribute(string $key, $value): void {
		$currentAttributes = $this->getAttributes();
		$currentAttributes[$key] = $value;
		$this->row[self::FAttributes] = json_encode($currentAttributes);
	}
	
	public function getCreatedAt(): int {
		return intval($this->row['task_created_at']);
	}

	public function setStatus(TaskStatusEnum $newStatus): void {
		$this->row[self::FStatus] = $newStatus->value;
	}
}

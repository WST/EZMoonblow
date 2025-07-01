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
	
		// Let’s check if such a task already exists.
		$taskSudahAda = false;
		$existingTasks = $database->selectAllRows(
			QueueTask::getTableName(),
			'*',
			[self::FRecipient => $appName, self::FType => TaskTypeEnum::DRAW_CANDLESTICK_CHART->value],
		);
		
		// If the attributes match, the task already exists then.
		foreach ($existingTasks as $task) {
			$taskAttributes = json_decode($task[self::FAttributes], true);
			if ($sender->taskAttributesMatch($taskAttributes)) {
				$taskSudahAda = true;
				break;
			}
		}
		
		// Skip adding an existing task.
		if ($taskSudahAda) return;
		
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

	public function getStatus(): TaskStatusEnum {
		return TaskStatusEnum::from($this->row['task_status']);
	}
	
	public function getRecipient(): TaskRecipientEnum {
		return TaskRecipientEnum::from($this->row['task_recipient']);
	}
	
	public function getType(): TaskTypeEnum {
		return TaskTypeEnum::from($this->row['task_type']);
	}

	public function getAttributes(): array {
		return json_decode($this->row['task_attributes'], true);
	}
}

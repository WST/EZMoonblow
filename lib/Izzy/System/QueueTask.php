<?php

namespace Izzy\System;

use Izzy\Enums\TaskRecipientEnum;
use Izzy\Enums\TaskStatusEnum;
use Izzy\Enums\TaskTypeEnum;
use Izzy\Financial\Market;
use Izzy\RealApplications\Analyzer;
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

	public static function updateChart(Market $sender): void {
		$attributes = [
			'pair' => $sender->getPair()->getTicker(),
			'timeframe' => $sender->getPair()->getTimeframe(),
			'marketType' => $sender->getPair()->getMarketType(),
			'exchange' => $sender->getExchange()->getName(),
		];
		$row = [
			self::FCreatedAt => time(),
			self::FAttributes => json_encode($attributes),
			self::FRecipient => Analyzer::getApplicationName(),
			self::FType => TaskTypeEnum::DRAW_CANDLESTICK_CHART->value,
			self::FStatus => TaskStatusEnum::PENDING->value,
		];
		var_dump($row);
		$task = new self($sender->getDatabase(), $row, self::FId);
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
}

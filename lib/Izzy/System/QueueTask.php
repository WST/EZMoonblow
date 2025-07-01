<?php

namespace Izzy\System;

use Izzy\Enums\TaskRecipientEnum;
use Izzy\Enums\TaskStatusEnum;
use Izzy\Enums\TaskTypeEnum;
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

	/**
	 * Name of the database table to store queue tasks.
	 * @return string
	 */
	public static function getTableName(): string {
		return 'tasks';
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

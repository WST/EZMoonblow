<?php

namespace Izzy\Enums;

enum TaskStatusEnum: string
{
	case PENDING = 'Pending';
	case INPROGRESS = 'InProgress';
	case COMPLETED = 'Completed';
	case FAILED = 'Failed';
	
	public function isPending(): bool {
		return $this === self::PENDING;
	}
	
	public function isInProgress(): bool {
		return $this === self::INPROGRESS;
	}
	
	public function isCompleted(): bool {
		return $this === self::COMPLETED;
	}
	
	public function isFailed(): bool {
		return $this === self::FAILED;
	}
}

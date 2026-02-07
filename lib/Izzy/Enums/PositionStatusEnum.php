<?php

namespace Izzy\Enums;

enum PositionStatusEnum: string
{
	/**
	 * The position has placed a limit order, but it wasn't executed yet.
	 */
	case PENDING = 'PENDING';

	/**
	 * The position is open and active. For spot markets, this means having a certain amount
	 * of the tradable object on the balance.
	 */
	case OPEN = 'OPEN';

	/**
	 * The position is finished.
	 */
	case FINISHED = 'FINISHED';

	/**
	 * Something unexpected happened to the position.
	 */
	case ERROR = 'ERROR';

	/**
	 * The position was cancelled without entering "OPEN" state.
	 */
	case CANCELED = 'CANCELED';

	public function isOpen(): bool {
		return $this === self::OPEN;
	}

	public function isPending(): bool {
		return $this === self::PENDING;
	}

	public function isFinished(): bool {
		return $this === self::FINISHED;
	}

	public function isError(): bool {
		return $this === self::ERROR;
	}

	public function isCanceled(): bool {
		return $this === self::CANCELED;
	}

	public function toString(): string {
		return $this->value;
	}

	/**
	 * Get SQL FIELD expression for sorting positions by status priority.
	 * Order: OPEN, PENDING, FINISHED, CANCELED, ERROR.
	 *
	 * @param string $columnName Column name containing the status.
	 * @return string SQL FIELD expression.
	 */
	public static function getSqlSortOrder(string $columnName = 'position_status'): string {
		$values = implode(', ', array_map(
			fn(self $case) => "'{$case->value}'",
			[self::OPEN, self::PENDING, self::FINISHED, self::CANCELED, self::ERROR]
		));
		return "FIELD($columnName, $values)";
	}
}

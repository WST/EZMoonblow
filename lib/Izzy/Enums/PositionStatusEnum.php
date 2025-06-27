<?php

namespace Izzy\Enums;

use InvalidArgumentException;

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
}

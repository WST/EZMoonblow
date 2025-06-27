<?php

namespace Izzy\Enums;

enum PositionStatusEnum: string
{
	/**
	 * The position has placed a limit order, but it wasn’t executed yet.
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
	 * The position was cancelled without entering “OPEN” state.
	 */
	case CANCELED = 'CANCELED';
}

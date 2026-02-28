<?php

namespace Izzy\Exchanges\Gate;

use Izzy\Enums\PositionDirectionEnum;

/**
 * Price order types for closing positions on Gate.io.
 */
enum GatePositionCloseTypeEnum: string
{
	case CloseLongPosition = 'plan-close-long-position';

	case CloseShortPosition = 'plan-close-short-position';

	/**
	 * Get the close type for a position direction.
	 */
	public static function fromDirection(PositionDirectionEnum $direction): self {
		return $direction->isLong() ? self::CloseLongPosition : self::CloseShortPosition;
	}
}

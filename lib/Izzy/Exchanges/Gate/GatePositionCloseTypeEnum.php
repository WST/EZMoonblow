<?php

namespace Izzy\Exchanges\Gate;

/**
 * Price order types for closing positions on Gate.io.
 */
enum GatePositionCloseTypeEnum: string
{
	case CloseLongPosition = 'close-long-position';

	case CloseShortPosition = 'close-short-position';

	/**
	 * Get the close type for a position direction.
	 */
	public static function fromDirection(\Izzy\Enums\PositionDirectionEnum $direction): self {
		return $direction->isLong() ? self::CloseLongPosition : self::CloseShortPosition;
	}
}

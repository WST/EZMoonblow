<?php

namespace Izzy\Exchanges\Gate;

/**
 * Auto-size values for closing positions in Gate.io dual mode.
 */
enum GateAutoSizeEnum: string
{
	case CloseLong = 'close_long';

	case CloseShort = 'close_short';

	/**
	 * Get the auto_size value for a position direction.
	 */
	public static function fromDirection(\Izzy\Enums\PositionDirectionEnum $direction): self {
		return $direction->isLong() ? self::CloseLong : self::CloseShort;
	}
}

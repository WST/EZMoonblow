<?php

namespace Izzy\Exchanges\Gate;

use Izzy\Enums\PositionDirectionEnum;

/**
 * Price order types for closing positions on Gate.io.
 */
enum GatePositionCloseTypeEnum: string
{
	/** Position TP/SL — close ALL long positions (Entire Position). */
	case CloseLongPosition = 'close-long-position';

	/** Position TP/SL — close ALL short positions (Entire Position). */
	case CloseShortPosition = 'close-short-position';

	/** Plan TP/SL — close all or partial long positions. */
	case PlanCloseLongPosition = 'plan-close-long-position';

	/** Plan TP/SL — close all or partial short positions. */
	case PlanCloseShortPosition = 'plan-close-short-position';

	/**
	 * Get the order_type for closing the entire position (TP/SL).
	 */
	public static function entireClose(PositionDirectionEnum $direction): self {
		return $direction->isLong() ? self::CloseLongPosition : self::CloseShortPosition;
	}

	/**
	 * Get all close-related order_type values for a given direction.
	 * Useful for cancellation to match orders regardless of how they were created.
	 *
	 * @return string[]
	 */
	public static function allValuesForDirection(PositionDirectionEnum $direction): array {
		if ($direction->isLong()) {
			return [self::CloseLongPosition->value, self::PlanCloseLongPosition->value];
		}
		return [self::CloseShortPosition->value, self::PlanCloseShortPosition->value];
	}
}

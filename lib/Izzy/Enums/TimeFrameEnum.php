<?php

namespace Izzy\Enums;

/**
 * Timeframe enum.
 */
enum TimeFrameEnum: string
{
	case TF_1MINUTE = '1m';
	case TF_3MINUTES = '3m';
	case TF_5MINUTES = '5m';
	case TF_15MINUTES = '15m';
	case TF_30MINUTES = '30m';
	case TF_1HOUR = '1h';
	case TF_2HOURS = '2h';
	case TF_4HOURS = '4h';
	case TF_6HOURS = '6h';
	case TF_12HOURS = '12h';
	case TF_1DAY = '1d';
	case TF_1WEEK = '1w';
	case TF_1MONTH = '1M';

	public function toMilliseconds(): int {
		return $this->toSeconds() * 1000;
	}

	public function toSeconds(): int {
		return match ($this) {
			self::TF_1MINUTE => 60,
			self::TF_3MINUTES => 3 * 60,
			self::TF_5MINUTES => 5 * 60,
			self::TF_15MINUTES => 15 * 60,
			self::TF_30MINUTES => 30 * 60,
			self::TF_1HOUR => 60 * 60,
			self::TF_2HOURS => 2 * 60 * 60,
			self::TF_4HOURS => 4 * 60 * 60,
			self::TF_6HOURS => 6 * 60 * 60,
			self::TF_12HOURS => 12 * 60 * 60,
			self::TF_1DAY => 24 * 60 * 60,
			self::TF_1WEEK => 7 * 24 * 60 * 60,
			self::TF_1MONTH => 30 * 24 * 60 * 60,
			default => 0,
		};
	}
}

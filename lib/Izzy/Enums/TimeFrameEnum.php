<?php

namespace Izzy\Enums;

/**
 * Timeframe enum.
 */
enum TimeFrameEnum: string {
	case TF_15MINUTES = '15m';
	case TF_1HOUR = '1h';
	case TF_4HOURS = '4h';

	public function toMilliseconds(): int {
		return match ($this) {
			'1m' => 60 * 1000,
			'3m' => 3 * 60 * 1000,
			'5m' => 5 * 60 * 1000,
			'15m' => 15 * 60 * 1000,
			'30m' => 30 * 60 * 1000,
			'1h' => 60 * 60 * 1000,
			'2h' => 2 * 60 * 60 * 1000,
			'4h' => 4 * 60 * 60 * 1000,
			'6h' => 6 * 60 * 60 * 1000,
			'12h' => 12 * 60 * 60 * 1000,
			'1d' => 24 * 60 * 60 * 1000,
			'1w' => 7 * 24 * 60 * 60 * 1000,
			'1M' => 30 * 24 * 60 * 60 * 1000,
			default => 0,
		};
	}
}

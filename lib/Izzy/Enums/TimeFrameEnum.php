<?php

namespace Izzy\Enums;

/**
 * Timeframe enum.
 */
enum TimeFrameEnum: string {
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
			'1m' => 60,
			'3m' => 3 * 60,
			'5m' => 5 * 60,
			'15m' => 15 * 60,
			'30m' => 30 * 60,
			'1h' => 60 * 60,
			'2h' => 2 * 60 * 60,
			'4h' => 4 * 60 * 60,
			'6h' => 6 * 60 * 60,
			'12h' => 12 * 60 * 60,
			'1d' => 24 * 60 * 60,
			'1w' => 7 * 24 * 60 * 60,
			'1M' => 30 * 24 * 60 * 60,
			default => 0,
		};
	}
}

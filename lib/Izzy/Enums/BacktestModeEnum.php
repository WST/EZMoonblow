<?php

namespace Izzy\Enums;

enum BacktestModeEnum: string
{
	case MANUAL = 'Manual';
	case AUTO = 'Auto';

	public function isManual(): bool {
		return $this === self::MANUAL;
	}

	public function isAuto(): bool {
		return $this === self::AUTO;
	}
}

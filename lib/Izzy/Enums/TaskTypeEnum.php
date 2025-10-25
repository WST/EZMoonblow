<?php

namespace Izzy\Enums;

enum TaskTypeEnum: string {
	case DRAW_CANDLESTICK_CHART = 'Draw Candlestick Chart';

	case TELEGRAM_WANT_NEW_POSITION = 'Want New Position';

	public function isDrawCandlestickChart(): bool {
		return $this === self::DRAW_CANDLESTICK_CHART;
	}

	public function isTelegramWantNewPosition(): bool {
		return $this === self::TELEGRAM_WANT_NEW_POSITION;
	}
}

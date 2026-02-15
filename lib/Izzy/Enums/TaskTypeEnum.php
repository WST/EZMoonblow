<?php

namespace Izzy\Enums;

enum TaskTypeEnum: string
{
	case DRAW_CANDLESTICK_CHART = 'Draw Candlestick Chart';

	case TELEGRAM_WANT_NEW_POSITION = 'Want New Position';

	case TELEGRAM_POSITION_OPENED = 'Position Opened';

	case TELEGRAM_POSITION_CLOSED = 'Position Closed';

	case TELEGRAM_BREAKEVEN_LOCK = 'Breakeven Lock';

	case LOAD_CANDLES = 'Load Candles';

	public function isDrawCandlestickChart(): bool {
		return $this === self::DRAW_CANDLESTICK_CHART;
	}

	public function isTelegramWantNewPosition(): bool {
		return $this === self::TELEGRAM_WANT_NEW_POSITION;
	}

	public function isLoadCandles(): bool {
		return $this === self::LOAD_CANDLES;
	}
}

<?php

namespace Izzy\Enums;

enum TaskTypeEnum: string
{
	case DRAW_CANDLESTICK_CHART = 'Draw Candlestick Chart';
	
	public function isDrawCandlestickChart(): bool {
		return $this === self::DRAW_CANDLESTICK_CHART;
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSE\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class RSILongThreshold extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'RSI oversold threshold for longs (1H)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSE';
	}

	protected static function getClassDefault(): string {
		return '30';
	}
}

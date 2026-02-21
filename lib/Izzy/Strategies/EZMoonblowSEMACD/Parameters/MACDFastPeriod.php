<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class MACDFastPeriod extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'macdFastPeriod';
	}

	public static function getLabel(): string {
		return 'MACD fast EMA period';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	protected static function getClassDefault(): string {
		return '12';
	}
}

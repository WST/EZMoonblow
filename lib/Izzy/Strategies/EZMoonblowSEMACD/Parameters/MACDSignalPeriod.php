<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class MACDSignalPeriod extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'macdSignalPeriod';
	}

	public static function getLabel(): string {
		return 'MACD signal EMA period';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	protected static function getClassDefault(): string {
		return '9';
	}
}

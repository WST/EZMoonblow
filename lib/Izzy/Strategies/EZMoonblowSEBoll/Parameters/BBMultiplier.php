<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBMultiplier extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'bbMultiplier';
	}

	public static function getLabel(): string {
		return 'Bollinger Bands StdDev multiplier';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	protected static function getClassDefault(): string {
		return '2.0';
	}
}

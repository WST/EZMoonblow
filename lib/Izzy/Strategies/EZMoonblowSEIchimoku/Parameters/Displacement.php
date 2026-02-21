<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class Displacement extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Cloud displacement (Chikou shift)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	protected static function getClassDefault(): string {
		return '26';
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBPeriod extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'bbPeriod';
	}

	public static function getLabel(): string {
		return 'Bollinger Bands period';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	protected static function getClassDefault(): string {
		return '20';
	}
}

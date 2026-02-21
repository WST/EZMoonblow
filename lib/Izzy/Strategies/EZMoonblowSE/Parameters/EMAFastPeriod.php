<?php

namespace Izzy\Strategies\EZMoonblowSE\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class EMAFastPeriod extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'emaFastPeriod';
	}

	public static function getLabel(): string {
		return 'EMA fast period (1D)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSE';
	}

	protected static function getClassDefault(): string {
		return '20';
	}
}

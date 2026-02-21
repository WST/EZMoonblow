<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class PriceDeviationMultiplierShort extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'priceDeviationMultiplierShort';
	}

	public static function getLabel(): string {
		return 'Price deviation multiplier for subsequent orders (Short)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '2';
	}
}

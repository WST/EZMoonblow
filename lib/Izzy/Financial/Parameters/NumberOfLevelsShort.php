<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class NumberOfLevelsShort extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'numberOfLevelsShort';
	}

	public static function getLabel(): string {
		return 'Number of DCA orders including entry (Short)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '6';
	}
}

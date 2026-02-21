<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class NumberOfLevels extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'numberOfLevels';
	}

	public static function getLabel(): string {
		return 'Number of DCA orders including the entry order';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '5';
	}
}

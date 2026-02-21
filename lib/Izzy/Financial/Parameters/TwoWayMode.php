<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class TwoWayMode extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Two-Way mode (simultaneous Long & Short)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return 'false';
	}
}

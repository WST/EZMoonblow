<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class RSIPeriod extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'RSI period';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return AbstractStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '14';
	}
}

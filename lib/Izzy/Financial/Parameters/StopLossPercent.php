<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class StopLossPercent extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'stopLossPercent';
	}

	public static function getLabel(): string {
		return 'Stop-loss distance (%)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '5';
	}
}

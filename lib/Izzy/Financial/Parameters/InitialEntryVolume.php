<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

/**
 * Initial entry volume for DCA strategies.
 */
class InitialEntryVolume extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Initial entry volume (USDT, %, %M, or base currency)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::STRING;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '3%';
	}
}

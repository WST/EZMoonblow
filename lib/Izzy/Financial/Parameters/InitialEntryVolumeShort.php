<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class InitialEntryVolumeShort extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'initialEntryVolumeShort';
	}

	public static function getLabel(): string {
		return 'Initial Entry volume (Short)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::STRING;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '10';
	}
}

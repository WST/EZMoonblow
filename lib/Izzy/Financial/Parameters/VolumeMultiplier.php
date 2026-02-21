<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class VolumeMultiplier extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'volumeMultiplier';
	}

	public static function getLabel(): string {
		return 'Volume multiplier for each subsequent order';
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

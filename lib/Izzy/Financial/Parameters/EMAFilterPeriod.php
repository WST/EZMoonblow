<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class EMAFilterPeriod extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'EMA filter period';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return AbstractStrategy::getStrategySettingGroupTitle();
	}

	public static function getEnabledCondition(): ?array {
		return ['paramKey' => EMATrendFilter::getName(), 'value' => 'true'];
	}

	protected static function getClassDefault(): string {
		return '50';
	}
}

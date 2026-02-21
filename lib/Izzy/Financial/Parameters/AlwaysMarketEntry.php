<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class AlwaysMarketEntry extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'alwaysMarketEntry';
	}

	public static function getLabel(): string {
		return 'Always execute entry order as market';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return 'true';
	}

	public static function isBacktestRelevant(): bool {
		return false;
	}
}

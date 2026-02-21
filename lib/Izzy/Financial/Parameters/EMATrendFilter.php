<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class EMATrendFilter extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'emaTrendFilter';
	}

	public static function getLabel(): string {
		return 'EMA trend filter';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return AbstractStrategy::getStrategySettingGroupTitle();
	}

	public static function hasExclamationMark(): bool {
		return true;
	}

	public static function getExclamationMarkTooltip(): string {
		return 'Requires candles of the selected filter timeframe to be loaded for this pair.';
	}

	protected static function getClassDefault(): string {
		return 'false';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Blocks entries that go against the higher-timeframe trend.';
	}
}

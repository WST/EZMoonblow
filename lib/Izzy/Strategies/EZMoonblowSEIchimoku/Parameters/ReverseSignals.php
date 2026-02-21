<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ReverseSignals extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'reverseSignals';
	}

	public static function getLabel(): string {
		return 'Reverse signals (mean-reversion mode)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Swap long/short signals. Classic Ichimoku is trend-following (works on daily TF). Enabling reverse mode turns it into a mean-reversion strategy, which often works better on lower timeframes (1h, 4h).';
	}

	protected static function getClassDefault(): string {
		return 'false';
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class RSINeutralFilter extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'RSI neutral zone filter';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Only enter when RSI is inside the neutral zone (not overbought/oversold), confirming a sideways market.';
	}

	protected static function getClassDefault(): string {
		return 'false';
	}
}

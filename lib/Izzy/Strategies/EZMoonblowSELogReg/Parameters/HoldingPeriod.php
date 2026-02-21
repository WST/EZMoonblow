<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class HoldingPeriod extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'holdingPeriod';
	}

	public static function getLabel(): string {
		return 'Holding period (candles)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSELogReg';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'After entering a position, prevent re-entry for this many candles. '
			. 'Mimics the Pine Script holding period mechanic. Set to 0 to disable.';
	}

	protected static function getClassDefault(): string {
		return '5';
	}
}

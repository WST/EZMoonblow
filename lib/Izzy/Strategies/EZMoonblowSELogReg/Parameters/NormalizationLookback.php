<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class NormalizationLookback extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Normalization lookback';
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
		return 'Period for minimax normalization of loss and prediction into the price range. '
			. 'Small values (2-5) make signals noisy; larger values (50-120) produce more stable signals.';
	}

	protected static function getClassDefault(): string {
		return '50';
	}
}

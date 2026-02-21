<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class LookbackWindow extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'lookbackWindow';
	}

	public static function getLabel(): string {
		return 'Lookback window';
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
		return 'Number of candles used to train the logistic regression model on each bar.';
	}

	protected static function getClassDefault(): string {
		return '5';
	}
}

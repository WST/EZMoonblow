<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class LearningRate extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Learning rate';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSELogReg';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Gradient descent learning rate. Smaller values are more stable but converge slower.';
	}

	protected static function getClassDefault(): string {
		return '0.0009';
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class TrainingIterations extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'trainingIterations';
	}

	public static function getLabel(): string {
		return 'Training iterations';
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
		return 'Number of gradient descent iterations per bar. Higher values improve accuracy but increase computation time.';
	}

	protected static function getClassDefault(): string {
		return '1000';
	}
}

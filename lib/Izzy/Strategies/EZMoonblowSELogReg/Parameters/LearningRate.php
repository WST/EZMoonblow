<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class LearningRate extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'learningRate';
	}

	public function getLabel(): string {
		return 'Learning rate';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSELogReg';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Gradient descent learning rate. Smaller values are more stable but converge slower.';
	}

	protected function getClassDefault(): string {
		return '0.0009';
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class TrainingIterations extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'trainingIterations';
	}

	public function getLabel(): string {
		return 'Training iterations';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSELogReg';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Number of gradient descent iterations per bar. Higher values improve accuracy but increase computation time.';
	}

	protected function getClassDefault(): string {
		return '1000';
	}
}

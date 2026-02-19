<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class LookbackWindow extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'lookbackWindow';
	}

	public function getLabel(): string {
		return 'Lookback window';
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
		return 'Number of candles used to train the logistic regression model on each bar.';
	}

	protected function getClassDefault(): string {
		return '5';
	}
}

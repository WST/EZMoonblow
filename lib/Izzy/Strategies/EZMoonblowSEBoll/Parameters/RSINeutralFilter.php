<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class RSINeutralFilter extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'rsiNeutralFilter';
	}

	public function getLabel(): string {
		return 'RSI neutral zone filter';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Only enter when RSI is inside the neutral zone (not overbought/oversold), confirming a sideways market.';
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}

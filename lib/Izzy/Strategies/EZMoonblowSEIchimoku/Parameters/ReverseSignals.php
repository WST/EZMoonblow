<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ReverseSignals extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'reverseSignals';
	}

	public function getLabel(): string {
		return 'Reverse signals (mean-reversion mode)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Swap long/short signals. Classic Ichimoku is trend-following (works on daily TF). Enabling reverse mode turns it into a mean-reversion strategy, which often works better on lower timeframes (1h, 4h).';
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}

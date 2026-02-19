<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class HoldingPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'holdingPeriod';
	}

	public function getLabel(): string {
		return 'Holding period (candles)';
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
		return 'After entering a position, prevent re-entry for this many candles. '
			. 'Mimics the Pine Script holding period mechanic. Set to 0 to disable.';
	}

	protected function getClassDefault(): string {
		return '5';
	}
}

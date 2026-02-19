<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class CooldownCandles extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'cooldownCandles';
	}

	public function getLabel(): string {
		return 'Cooldown (candles)';
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
		return 'Minimum number of candles between consecutive entries in the same direction.';
	}

	protected function getClassDefault(): string {
		return '0';
	}
}

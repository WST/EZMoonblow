<?php

namespace Izzy\Strategies\EZMoonblowSE\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class CooldownCandles extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'cooldownCandles';
	}

	public function getLabel(): string {
		return 'Cooldown between entries (candles)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSE';
	}

	protected function getClassDefault(): string {
		return '0';
	}
}

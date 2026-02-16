<?php

namespace Izzy\Strategies\EZMoonblowSE\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class RSIShortThreshold extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'rsiShortThreshold';
	}

	public function getLabel(): string {
		return 'RSI overbought threshold for shorts (1H)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSE';
	}

	protected function getClassDefault(): string {
		return '70';
	}
}

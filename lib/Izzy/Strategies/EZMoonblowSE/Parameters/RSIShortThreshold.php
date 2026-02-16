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

	protected function getClassDefault(): string {
		return '70';
	}
}

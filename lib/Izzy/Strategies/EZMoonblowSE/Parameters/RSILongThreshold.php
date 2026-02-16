<?php

namespace Izzy\Strategies\EZMoonblowSE\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class RSILongThreshold extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'rsiLongThreshold';
	}

	public function getLabel(): string {
		return 'RSI oversold threshold for longs (1H)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	protected function getClassDefault(): string {
		return '30';
	}
}

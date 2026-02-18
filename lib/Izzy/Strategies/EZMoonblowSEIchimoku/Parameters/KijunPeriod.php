<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class KijunPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'kijunPeriod';
	}

	public function getLabel(): string {
		return 'Kijun-sen period (Base Line)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	protected function getClassDefault(): string {
		return '26';
	}
}

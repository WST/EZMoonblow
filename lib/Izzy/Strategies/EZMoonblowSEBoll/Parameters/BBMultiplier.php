<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBMultiplier extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'bbMultiplier';
	}

	public function getLabel(): string {
		return 'Bollinger Bands StdDev multiplier';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	protected function getClassDefault(): string {
		return '2.0';
	}
}

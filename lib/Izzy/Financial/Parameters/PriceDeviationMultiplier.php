<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class PriceDeviationMultiplier extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'priceDeviationMultiplier';
	}

	public function getLabel(): string {
		return 'Price deviation multiplier for subsequent orders';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	protected function getClassDefault(): string {
		return '1.3';
	}
}

<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class PriceDeviation extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'priceDeviation';
	}

	public function getLabel(): string {
		return 'Price deviation for first averaging (%)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	protected function getClassDefault(): string {
		return '10';
	}
}

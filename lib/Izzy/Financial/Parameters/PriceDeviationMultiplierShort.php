<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class PriceDeviationMultiplierShort extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'priceDeviationMultiplierShort';
	}

	public function getLabel(): string {
		return 'Price deviation multiplier for subsequent orders (Short)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return 'DCA (Short)';
	}

	protected function getClassDefault(): string {
		return '2';
	}
}

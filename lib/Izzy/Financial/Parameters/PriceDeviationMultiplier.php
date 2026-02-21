<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
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

	public function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '1.6';
	}
}

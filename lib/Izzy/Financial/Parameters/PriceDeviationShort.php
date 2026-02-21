<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class PriceDeviationShort extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'priceDeviationShort';
	}

	public function getLabel(): string {
		return 'Price deviation for first averaging (Short, %)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '10';
	}
}

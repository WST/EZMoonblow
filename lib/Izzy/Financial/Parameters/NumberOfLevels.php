<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class NumberOfLevels extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'numberOfLevels';
	}

	public function getLabel(): string {
		return 'Number of DCA orders including the entry order';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'DCA';
	}

	protected function getClassDefault(): string {
		return '4';
	}
}

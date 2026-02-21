<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class NumberOfLevelsShort extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'numberOfLevelsShort';
	}

	public function getLabel(): string {
		return 'Number of DCA orders including entry (Short)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '6';
	}
}

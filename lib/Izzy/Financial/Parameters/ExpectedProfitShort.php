<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class ExpectedProfitShort extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'expectedProfitShort';
	}

	public function getLabel(): string {
		return 'Expected profit percentage (Short)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '1.5';
	}
}

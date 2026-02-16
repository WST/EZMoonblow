<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ExpectedProfit extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'expectedProfit';
	}

	public function getLabel(): string {
		return 'Expected profit percentage';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	protected function getClassDefault(): string {
		return '1.5';
	}
}

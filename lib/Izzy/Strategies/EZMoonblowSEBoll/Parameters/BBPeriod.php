<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'bbPeriod';
	}

	public function getLabel(): string {
		return 'Bollinger Bands period';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	protected function getClassDefault(): string {
		return '20';
	}
}

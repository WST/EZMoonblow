<?php

namespace Izzy\Strategies\EZMoonblowSE\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class EMAFastPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'emaFastPeriod';
	}

	public function getLabel(): string {
		return 'EMA fast period (1D)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	protected function getClassDefault(): string {
		return '20';
	}
}

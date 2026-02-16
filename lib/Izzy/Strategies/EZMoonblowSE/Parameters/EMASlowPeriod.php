<?php

namespace Izzy\Strategies\EZMoonblowSE\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class EMASlowPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'emaSlowPeriod';
	}

	public function getLabel(): string {
		return 'EMA slow period (1D)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSE';
	}

	protected function getClassDefault(): string {
		return '50';
	}
}

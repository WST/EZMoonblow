<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class StopLossPercent extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'stopLossPercent';
	}

	public function getLabel(): string {
		return 'Stop-loss distance (%)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	protected function getClassDefault(): string {
		return '2';
	}
}

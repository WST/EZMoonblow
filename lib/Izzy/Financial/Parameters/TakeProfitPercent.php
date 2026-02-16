<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class TakeProfitPercent extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'takeProfitPercent';
	}

	public function getLabel(): string {
		return 'Take-profit distance (%)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return 'Single Entry';
	}

	protected function getClassDefault(): string {
		return '10';
	}
}

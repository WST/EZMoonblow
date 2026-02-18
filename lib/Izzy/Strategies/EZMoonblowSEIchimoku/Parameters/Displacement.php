<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class Displacement extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'displacement';
	}

	public function getLabel(): string {
		return 'Cloud displacement (Chikou shift)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	protected function getClassDefault(): string {
		return '26';
	}
}

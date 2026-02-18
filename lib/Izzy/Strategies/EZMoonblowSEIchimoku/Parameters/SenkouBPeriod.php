<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class SenkouBPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'senkouBPeriod';
	}

	public function getLabel(): string {
		return 'Senkou Span B period';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	protected function getClassDefault(): string {
		return '52';
	}
}

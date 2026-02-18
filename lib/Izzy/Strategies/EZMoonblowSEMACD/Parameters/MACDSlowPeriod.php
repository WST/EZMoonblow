<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class MACDSlowPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'macdSlowPeriod';
	}

	public function getLabel(): string {
		return 'MACD slow EMA period';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	protected function getClassDefault(): string {
		return '26';
	}
}

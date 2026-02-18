<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class MACDFastPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'macdFastPeriod';
	}

	public function getLabel(): string {
		return 'MACD fast EMA period';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	protected function getClassDefault(): string {
		return '12';
	}
}

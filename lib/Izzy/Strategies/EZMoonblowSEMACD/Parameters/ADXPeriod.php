<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ADXPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'adxPeriod';
	}

	public function getLabel(): string {
		return 'ADX period';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	public function getEnabledCondition(): ?array {
		return ['paramKey' => 'adxFilter', 'value' => 'true'];
	}

	protected function getClassDefault(): string {
		return '14';
	}
}

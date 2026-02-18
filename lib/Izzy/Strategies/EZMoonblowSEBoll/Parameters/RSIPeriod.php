<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class RSIPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'rsiPeriod';
	}

	public function getLabel(): string {
		return 'RSI period';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public function getEnabledCondition(): ?array {
		return ['paramKey' => 'rsiNeutralFilter', 'value' => 'true'];
	}

	protected function getClassDefault(): string {
		return '14';
	}
}

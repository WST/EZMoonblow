<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class RSINeutralLow extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'rsiNeutralLow';
	}

	public function getLabel(): string {
		return 'RSI neutral zone low';
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
		return '30';
	}
}

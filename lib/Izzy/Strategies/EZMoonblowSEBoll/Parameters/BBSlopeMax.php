<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBSlopeMax extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'bbSlopeMax';
	}

	public function getLabel(): string {
		return 'BB max slope (%)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Maximum allowed absolute % change of the Bollinger Band over the lookback period. Entries are rejected when the band slopes more steeply than this, filtering out trend-following touches.';
	}

	public function getEnabledCondition(): ?array {
		return ['paramKey' => 'bbSlopeFilter', 'value' => 'true'];
	}

	protected function getClassDefault(): string {
		return '1.0';
	}
}

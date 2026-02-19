<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBSlopePeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'bbSlopePeriod';
	}

	public function getLabel(): string {
		return 'BB slope lookback (candles)';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Number of candles to look back when measuring the Bollinger Band slope. Larger values smooth the measurement.';
	}

	public function getEnabledCondition(): ?array {
		return ['paramKey' => 'bbSlopeFilter', 'value' => 'true'];
	}

	protected function getClassDefault(): string {
		return '5';
	}
}

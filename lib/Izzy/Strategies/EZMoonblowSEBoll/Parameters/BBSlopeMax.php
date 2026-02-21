<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBSlopeMax extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'BB max slope (%)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Maximum allowed absolute % change of the Bollinger Band over the lookback period. Entries are rejected when the band slopes more steeply than this, filtering out trend-following touches.';
	}

	public static function getEnabledCondition(): ?array {
		return ['paramKey' => BBSlopeFilter::getName(), 'value' => 'true'];
	}

	protected static function getClassDefault(): string {
		return '1.0';
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBSlopePeriod extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'bbSlopePeriod';
	}

	public static function getLabel(): string {
		return 'BB slope lookback (candles)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Number of candles to look back when measuring the Bollinger Band slope. Larger values smooth the measurement.';
	}

	public static function getEnabledCondition(): ?array {
		return ['paramKey' => BBSlopeFilter::getName(), 'value' => 'true'];
	}

	protected static function getClassDefault(): string {
		return '5';
	}
}

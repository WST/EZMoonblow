<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class RSINeutralLow extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'RSI neutral zone low';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public static function getEnabledCondition(): ?array {
		return ['paramKey' => RSINeutralFilter::getName(), 'value' => 'true'];
	}

	protected static function getClassDefault(): string {
		return '30';
	}
}

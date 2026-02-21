<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ADXPeriod extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'adxPeriod';
	}

	public static function getLabel(): string {
		return 'ADX period';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	public static function getEnabledCondition(): ?array {
		return ['paramKey' => ADXFilter::getName(), 'value' => 'true'];
	}

	protected static function getClassDefault(): string {
		return '14';
	}
}

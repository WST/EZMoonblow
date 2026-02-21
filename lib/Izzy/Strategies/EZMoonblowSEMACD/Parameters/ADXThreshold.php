<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ADXThreshold extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'adxThreshold';
	}

	public static function getLabel(): string {
		return 'ADX threshold';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	public static function getEnabledCondition(): ?array {
		return ['paramKey' => ADXFilter::getName(), 'value' => 'true'];
	}

	protected static function getClassDefault(): string {
		return '20';
	}
}

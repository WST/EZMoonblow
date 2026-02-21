<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class CooldownCandles extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'cooldownCandles';
	}

	public static function getLabel(): string {
		return 'Cooldown between entries (candles)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	protected static function getClassDefault(): string {
		return '0';
	}
}

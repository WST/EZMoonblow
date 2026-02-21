<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class SignalType extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Entry signal type';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	protected static function getClassDefault(): string {
		return 'tk_cross';
	}

	public static function getOptions(): array {
		return [
			'tk_cross' => 'TK Cross (Tenkan/Kijun crossover)',
			'kumo_breakout' => 'Kumo Breakout (price breaks cloud)',
		];
	}
}

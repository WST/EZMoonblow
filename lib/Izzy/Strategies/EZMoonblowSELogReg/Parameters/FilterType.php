<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class FilterType extends AbstractStrategyParameter
{
	public const string NONE = 'none';
	public const string VOLATILITY = 'volatility';
	public const string VOLUME = 'volume';
	public const string BOTH = 'both';

	public static function getLabel(): string {
		return 'Filter type';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSELogReg';
	}

	public static function getOptions(): array {
		return [
			self::NONE => 'None',
			self::VOLATILITY => 'Volatility',
			self::VOLUME => 'Volume',
			self::BOTH => 'Both',
		];
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Volatility — ATR(1) > ATR(10), requires current volatility above average. '
			. 'Volume — RSI of volume > 49, requires active volume. '
			. 'Both — requires both conditions simultaneously.';
	}

	protected static function getClassDefault(): string {
		return self::NONE;
	}
}

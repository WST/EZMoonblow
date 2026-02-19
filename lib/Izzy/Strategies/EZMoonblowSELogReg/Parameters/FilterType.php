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

	public function getName(): string {
		return 'filterType';
	}

	public function getLabel(): string {
		return 'Filter type';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSELogReg';
	}

	public function getOptions(): array {
		return [
			self::NONE => 'None',
			self::VOLATILITY => 'Volatility',
			self::VOLUME => 'Volume',
			self::BOTH => 'Both',
		];
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Volatility — ATR(1) > ATR(10), requires current volatility above average. '
			. 'Volume — RSI of volume > 49, requires active volume. '
			. 'Both — requires both conditions simultaneously.';
	}

	protected function getClassDefault(): string {
		return self::NONE;
	}
}

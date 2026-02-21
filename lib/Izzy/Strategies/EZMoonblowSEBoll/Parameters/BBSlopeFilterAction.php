<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBSlopeFilterAction extends AbstractStrategyParameter
{
	public const string BLOCK = 'block';
	public const string INVERSE = 'inverse';

	public static function getLabel(): string {
		return 'BB slope filter action';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public static function getOptions(): array {
		return [
			self::BLOCK => 'Block Entry',
			self::INVERSE => 'Inverse Entry',
		];
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Block Entry — reject the signal when the band is sloping steeply. '
			. 'Inverse Entry — enter in the opposite direction (short instead of long and vice versa) '
			. 'when the slope filter fires, treating it as a trend-following signal.';
	}

	public static function getEnabledCondition(): ?array {
		return ['paramKey' => BBSlopeFilter::getName(), 'value' => 'true'];
	}

	protected static function getClassDefault(): string {
		return self::BLOCK;
	}
}

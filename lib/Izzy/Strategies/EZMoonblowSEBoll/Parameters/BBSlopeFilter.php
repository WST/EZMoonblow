<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBSlopeFilter extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'BB slope filter';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Reject entries when the relevant Bollinger Band is sloping steeply, indicating a strong trend where mean-reversion is unlikely to succeed.';
	}

	protected static function getClassDefault(): string {
		return 'false';
	}
}

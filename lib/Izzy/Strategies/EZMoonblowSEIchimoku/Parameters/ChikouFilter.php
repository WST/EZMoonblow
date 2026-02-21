<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ChikouFilter extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'chikouFilter';
	}

	public static function getLabel(): string {
		return 'Chikou Span confirmation';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'When enabled, longs require the current close to be above the close from displacement bars ago, and shorts require it to be below. Confirms momentum direction.';
	}

	protected static function getClassDefault(): string {
		return 'false';
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSEMACD\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class ADXFilter extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'ADX trend strength filter';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEMACD';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'When enabled, entries are only allowed when ADX is above the threshold, indicating a trending market.';
	}

	protected static function getClassDefault(): string {
		return 'false';
	}
}

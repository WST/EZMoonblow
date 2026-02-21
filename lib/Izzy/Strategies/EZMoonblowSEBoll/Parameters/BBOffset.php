<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class BBOffset extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'bbOffset';
	}

	public static function getLabel(): string {
		return 'BB penetration offset (%)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Price must move this % beyond the Bollinger Band before entry triggers. 0 = enter on band touch.';
	}

	protected static function getClassDefault(): string {
		return '0';
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class KumoFilter extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'kumoFilter';
	}

	public static function getLabel(): string {
		return 'Kumo (cloud) position filter';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	public static function getEnabledCondition(): ?array {
		return ['paramKey' => SignalType::getName(), 'value' => 'tk_cross'];
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'When enabled, TK Cross longs require price above the cloud, shorts require price below. Reduces false signals in ranging markets.';
	}

	protected static function getClassDefault(): string {
		return 'false';
	}
}

<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class BreakevenLockUseLimitOrder extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'breakevenLockUseLimitOrder';
	}

	public static function getLabel(): string {
		return 'Breakeven Lock via limit order';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return 'false';
	}

	public static function getEnabledCondition(): ?array {
		return [
			'paramKey' => BreakevenLockEnabled::getName(),
			'value' => 'true',
		];
	}
}

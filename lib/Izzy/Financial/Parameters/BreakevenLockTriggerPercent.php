<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class BreakevenLockTriggerPercent extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'breakevenLockTriggerPercent';
	}

	public static function getLabel(): string {
		return 'Breakeven Lock trigger (% of way to TP)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '10';
	}

	public static function getEnabledCondition(): ?array {
		return [
			'paramKey' => BreakevenLockEnabled::getName(),
			'value' => 'true',
		];
	}
}

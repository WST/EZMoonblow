<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class BreakevenLockEnabled extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'breakevenLockEnabled';
	}

	public static function getLabel(): string {
		return 'Breakeven Lock enabled';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public static function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return 'true';
	}
}

<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class StopLossCooldownMinutes extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Cooldown after stop-loss (min)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public static function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'After a stop-loss hit, wait this many minutes before opening a new position. 0 = no cooldown.';
	}

	protected static function getClassDefault(): string {
		return '0';
	}
}

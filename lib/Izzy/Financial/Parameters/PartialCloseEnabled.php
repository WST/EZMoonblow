<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class PartialCloseEnabled extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'partialCloseEnabled';
	}

	public static function getLabel(): string {
		return 'Partial Close enabled';
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
}

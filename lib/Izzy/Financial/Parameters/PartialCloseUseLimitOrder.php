<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class PartialCloseUseLimitOrder extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'partialCloseUseLimitOrder';
	}

	public static function getLabel(): string {
		return 'Partial Close via limit order';
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
			'paramKey' => PartialCloseEnabled::getName(),
			'value' => 'true',
		];
	}
}

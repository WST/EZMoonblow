<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class PartialCloseTriggerPercent extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'partialCloseTriggerPercent';
	}

	public static function getLabel(): string {
		return 'Partial Close trigger (% of way to TP)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '70';
	}

	public static function getEnabledCondition(): ?array {
		return [
			'paramKey' => PartialCloseEnabled::getName(),
			'value' => 'true',
		];
	}
}

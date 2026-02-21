<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class PartialClosePercent extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'partialClosePercent';
	}

	public static function getLabel(): string {
		return 'Partial Close portion (%)';
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

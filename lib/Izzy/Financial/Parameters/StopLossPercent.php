<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class StopLossPercent extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Stop-loss distance (%)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return AbstractSingleEntryStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '5';
	}

	public function getValue(): int|float|bool|string {
		return (float)str_replace('%', '', $this->getRawValue());
	}
}

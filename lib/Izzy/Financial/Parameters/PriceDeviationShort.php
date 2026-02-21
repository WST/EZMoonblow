<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class PriceDeviationShort extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Price deviation for first averaging (Short, %)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '10';
	}

	public function getValue(): int|float|bool|string {
		return (float)str_replace('%', '', $this->getRawValue());
	}
}

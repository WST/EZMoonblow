<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class ExpectedProfitShort extends AbstractStrategyParameter
{
	public static function getLabel(): string {
		return 'Expected profit percentage (Short)';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::FLOAT;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return '1.5';
	}

	public function getValue(): int|float|bool|string {
		return (float)str_replace('%', '', $this->getRawValue());
	}
}

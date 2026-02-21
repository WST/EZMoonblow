<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\DCAOffsetModeEnum;
use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class OffsetMode extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'offsetMode';
	}

	public static function getLabel(): string {
		return 'Price offset calculation mode';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public static function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected static function getClassDefault(): string {
		return DCAOffsetModeEnum::FROM_PREVIOUS->value;
	}

	public static function getOptions(): array {
		$options = [];
		foreach (DCAOffsetModeEnum::cases() as $case) {
			$options[$case->value] = $case->getDescription();
		}
		return $options;
	}
}

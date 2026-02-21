<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class EMATrendFilterTimeframe extends AbstractStrategyParameter
{
	public static function getName(): string {
		return 'emaTrendFilterTimeframe';
	}

	public static function getLabel(): string {
		return 'EMA filter timeframe';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public static function getGroup(): string {
		return AbstractStrategy::getStrategySettingGroupTitle();
	}

	public static function getEnabledCondition(): ?array {
		return ['paramKey' => EMATrendFilter::getName(), 'value' => 'true'];
	}

	protected static function getClassDefault(): string {
		return '1d';
	}

	public static function getOptions(): array {
		return [
			'1h' => 'Hourly (1h)',
			'1d' => 'Daily (1d)',
		];
	}
}

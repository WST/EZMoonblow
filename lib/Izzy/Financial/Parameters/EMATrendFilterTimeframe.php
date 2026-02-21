<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class EMATrendFilterTimeframe extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'emaTrendFilterTimeframe';
	}

	public function getLabel(): string {
		return 'EMA filter timeframe';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public function getGroup(): string {
		return AbstractStrategy::getStrategySettingGroupTitle();
	}

	public function getEnabledCondition(): ?array {
		return ['paramKey' => 'emaTrendFilter', 'value' => 'true'];
	}

	protected function getClassDefault(): string {
		return '1d';
	}

	public function getOptions(): array {
		return [
			'1h' => 'Hourly (1h)',
			'1d' => 'Daily (1d)',
		];
	}
}

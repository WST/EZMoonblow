<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class EMAFilterPeriod extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'emaSlowPeriod';
	}

	public function getLabel(): string {
		return 'EMA filter period';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return AbstractStrategy::getStrategySettingGroupTitle();
	}

	public function getEnabledCondition(): ?array {
		return ['paramKey' => 'emaTrendFilter', 'value' => 'true'];
	}

	protected function getClassDefault(): string {
		return '50';
	}
}

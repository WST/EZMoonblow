<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class UseLimitOrders extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'UseLimitOrders';
	}

	public function getLabel(): string {
		return 'Use limit orders instead of market orders';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return AbstractDCAStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return 'true';
	}

	public function isBacktestRelevant(): bool {
		return false;
	}
}

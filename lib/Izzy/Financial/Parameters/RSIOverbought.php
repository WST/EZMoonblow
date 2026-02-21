<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class RSIOverbought extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'rsiOverbought';
	}

	public function getLabel(): string {
		return 'RSI overbought threshold';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return AbstractStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '70';
	}
}

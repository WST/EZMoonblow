<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategy;
use Izzy\Financial\AbstractStrategyParameter;

class RSIOversold extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'rsiOversold';
	}

	public function getLabel(): string {
		return 'RSI oversold threshold';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::INT;
	}

	public function getGroup(): string {
		return AbstractStrategy::getStrategySettingGroupTitle();
	}

	protected function getClassDefault(): string {
		return '30';
	}
}

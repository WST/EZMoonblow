<?php

namespace Izzy\Financial\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class EMATrendFilter extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'emaTrendFilter';
	}

	public function getLabel(): string {
		return 'EMA trend filter';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'Single Entry';
	}

	public function hasExclamationMark(): bool {
		return true;
	}

	public function getExclamationMarkTooltip(): string {
		return 'Requires candles of the selected filter timeframe to be loaded for this pair.';
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}

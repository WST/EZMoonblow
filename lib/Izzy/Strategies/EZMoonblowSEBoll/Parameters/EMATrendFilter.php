<?php

namespace Izzy\Strategies\EZMoonblowSEBoll\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class EMATrendFilter extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'emaTrendFilter';
	}

	public function getLabel(): string {
		return 'EMA daily trend filter';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::BOOL;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEBoll';
	}

	public function hasExclamationMark(): bool {
		return true;
	}

	public function getExclamationMarkTooltip(): string {
		return 'Requires daily (1d) candles for this pair to be loaded in advance.';
	}

	protected function getClassDefault(): string {
		return 'false';
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSEIchimoku\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class SignalType extends AbstractStrategyParameter
{
	public function getName(): string {
		return 'signalType';
	}

	public function getLabel(): string {
		return 'Entry signal type';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSEIchimoku';
	}

	protected function getClassDefault(): string {
		return 'tk_cross';
	}

	public function getOptions(): array {
		return [
			'tk_cross' => 'TK Cross (Tenkan/Kijun crossover)',
			'kumo_breakout' => 'Kumo Breakout (price breaks cloud)',
		];
	}
}

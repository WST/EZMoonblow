<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class SignalMode extends AbstractStrategyParameter
{
	public const string PRICE = 'price';
	public const string CROSSOVER = 'crossover';

	public function getName(): string {
		return 'signalMode';
	}

	public function getLabel(): string {
		return 'Signal mode';
	}

	public function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public function getGroup(): string {
		return 'EZMoonblowSELogReg';
	}

	public function getOptions(): array {
		return [
			self::PRICE => 'Price',
			self::CROSSOVER => 'Crossover',
		];
	}

	public function hasQuestionMark(): bool {
		return true;
	}

	public function getQuestionMarkTooltip(): string {
		return 'Price — BUY when close > scaled_loss, SELL when close < scaled_loss. '
			. 'Crossover — BUY on crossover(scaled_loss, scaled_prediction), SELL on crossunder.';
	}

	protected function getClassDefault(): string {
		return self::PRICE;
	}
}

<?php

namespace Izzy\Strategies\EZMoonblowSELogReg\Parameters;

use Izzy\Enums\StrategyParameterTypeEnum;
use Izzy\Financial\AbstractStrategyParameter;

class SignalMode extends AbstractStrategyParameter
{
	public const string PRICE = 'price';
	public const string CROSSOVER = 'crossover';

	public static function getName(): string {
		return 'signalMode';
	}

	public static function getLabel(): string {
		return 'Signal mode';
	}

	public static function getType(): StrategyParameterTypeEnum {
		return StrategyParameterTypeEnum::SELECT;
	}

	public static function getGroup(): string {
		return 'EZMoonblowSELogReg';
	}

	public static function getOptions(): array {
		return [
			self::PRICE => 'Price',
			self::CROSSOVER => 'Crossover',
		];
	}

	public static function hasQuestionMark(): bool {
		return true;
	}

	public static function getQuestionMarkTooltip(): string {
		return 'Price — BUY when close > scaled_loss, SELL when close < scaled_loss. '
			. 'Crossover — BUY on crossover(scaled_loss, scaled_prediction), SELL on crossunder.';
	}

	protected static function getClassDefault(): string {
		return self::PRICE;
	}
}

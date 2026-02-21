<?php

namespace Izzy\Strategies\EZMoonblowDCA;

use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractStrategyParameter;
use Izzy\Financial\Parameters\RSIOverbought;
use Izzy\Financial\Parameters\RSIOversold;
use Izzy\Financial\Parameters\RSIPeriod;
use Izzy\Indicators\RSI;

class EZMoonblowDCA extends AbstractDCAStrategy
{
	public static function getDisplayName(): string {
		return 'RSI DCA';
	}

	public function useIndicators(): array {
		return [
			[
				'class' => RSI::class,
			'parameters' => [
				'period' => $this->getParam(RSIPeriod::getName())->getValue(),
				'overbought' => $this->getParam(RSIOverbought::getName())->getValue(),
				'oversold' => $this->getParam(RSIOversold::getName())->getValue(),
			],
			],
		];
	}

	public function shouldLong(): bool {
		$rsiSignal = $this->market->getLatestIndicatorSignal('RSI');
		return $rsiSignal === 'oversold';
	}

	public function doesLong(): bool {
		return true;
	}

	public function doesShort(): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 * @return AbstractStrategyParameter[]
	 */
	public static function getParameters(): array {
		return array_merge(parent::getParameters(), [
			new RSIPeriod(),
			new RSIOverbought(),
			new RSIOversold(),
		]);
	}
}

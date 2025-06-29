<?php

namespace Izzy\Strategies;

use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStrategy;

abstract class Strategy implements IStrategy
{
	protected ?IMarket $market;
	protected array $params;

	public function __construct(IMarket $market, array $params = []) {
		$this->market = $market;
		$this->params = $params;
	}

	public function getMarket(): ?IMarket {
		return $this->market;
	}

	public function setMarket(?IMarket $market): void {
		$this->market = $market;
	}

	/**
	 * Get strategy parameters.
	 * @return array Strategy parameters.
	 */
	public function getParams(): array {
		return $this->params;
	}
	
	public function getParam(string $name): ?string {
		return $this->params[$name] ?? null;
	}

	/**
	 * Set strategy parameters.
	 * 
	 * @param array $params Strategy parameters.
	 */
	public function setParams(array $params): void {
		$this->params = $params;
	}
	
	/**
	 * @inheritDoc
	 */
	public function useIndicators(): array {
		return [];
	}
}

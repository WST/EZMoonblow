<?php

namespace Izzy\Traits;

use Izzy\Enums\MarketTypeEnum;

trait HasMarketTypeTrait
{
	protected MarketTypeEnum $marketType;

	/**
	 * Check if this is a spot object.
	 *
	 * @return bool True if this is a spot object, false otherwise.
	 */
	public function isSpot(): bool {
		return $this->getMarketType()->isSpot();
	}

	/**
	 * Check if this is a futures object.
	 *
	 * @return bool True if this is a futures object, false otherwise.
	 */
	public function isFutures(): bool {
		return $this->getMarketType()->isFutures();
	}

	/**
	 * Check if this is an inverse futures object.
	 *
	 * @return bool True if this is an inverse futures object, false otherwise.
	 */
	public function isInverseFutures(): bool {
		return $this->getMarketType()->isInverseFutures();
	}

	public function getMarketType(): MarketTypeEnum {
		return $this->marketType;
	}

	public function setMarketType(MarketTypeEnum $marketType): void {
		$this->marketType = $marketType;
	}
}

<?php

namespace Izzy\Strategies;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Financial\Money;

/**
 * Represents a DCA strategy settings.
 */
class DCASettings
{
	/**
	 * Map of DCA orders.
	 * @var array 
	 */
	private array $orderMap = [];

	/**
	 * Number of DCA levels, including position entry.
	 * @var int 
	 */
	private int $numberOfLevels;

	/**
	 * Initial position volume.
	 * @var Money 
	 */
	private Money $entryVolume;

	/**
	 * Martingale coefficient.
	 * @var float 
	 */
	private float $volumeMultiplier;
	
	private float $priceDeviation;
	private float $priceDeviationMultiplier;
	private float $expectedProfit;

	/**
	 * Number of DCA levels, including position entry.
	 * @var int
	 */
	private int $numberOfLevelsShort;

	/**
	 * Initial position volume.
	 * @var Money
	 */
	private Money $entryVolumeShort;

	/**
	 * Martingale coefficient.
	 * @var float
	 */
	private float $volumeMultiplierShort;

	private float $priceDeviationShort;
	private float $priceDeviationMultiplierShort;
	private float $expectedProfitShort;
	private bool $useLimitOrders;

	/**
	 * Builds a DCASettings instance.
	 * @param bool $useLimitOrders Use limit orders instead of market.
	 * @param int $numberOfLevels Number of DCA levels, including position entry.
	 * @param Money $entryVolume Initial position volume.
	 * @param float $volumeMultiplier How much should be the next order greater than the previous one.
	 * @param float $priceDeviation The distance between the entry and the first averaging.
	 * @param float $priceDeviationMultiplier How much further (in percent) should be the next order from the entry price.
	 * @param float $expectedProfit Expected profit in percents of the position size.
	 */
	public function __construct(
		bool $useLimitOrders,
		int $numberOfLevels,
		Money $entryVolume,
		float $volumeMultiplier,
		float $priceDeviation,
		float $priceDeviationMultiplier,
		float $expectedProfit,
		int $numberOfLevelsShort = 0,
		?Money $entryVolumeShort = null,
		float $volumeMultiplierShort = 0.0,
		float $priceDeviationShort = 0.0,
		float $priceDeviationMultiplierShort = 0.0,
		float $expectedProfitShort = 0.0
	) {
		// Initialize the order map.
		$this->orderMap[PositionDirectionEnum::LONG->value] = [];
		$this->orderMap[PositionDirectionEnum::SHORT->value] = [];
		
		// First, build the order map for Long trades.
		$volume = $entryVolume->getAmount();
		$totalOffset = 0;
		$currentDeviation = $priceDeviation;
		for ($level = 0; $level < $numberOfLevels; $level++) {
			$this->orderMap[PositionDirectionEnum::LONG->value][$level] = ['volume' =>  $volume, 'offset' => - $totalOffset];
			$volume *= $volumeMultiplier;
			$totalOffset += $currentDeviation;
			$currentDeviation = $priceDeviationMultiplier * $currentDeviation;
		}

		// Then, build the order map for Short trades.
		if ($entryVolumeShort) {
			$volume = $entryVolumeShort->getAmount();
			$totalOffset = 0;
			$currentDeviation = $priceDeviationShort;
			for ($level = 0; $level < $numberOfLevelsShort; $level++) {
				$this->orderMap[PositionDirectionEnum::SHORT->value][$level] = ['volume' => $volume, 'offset' => $totalOffset];
				$volume *= $volumeMultiplierShort;
				$totalOffset += $currentDeviation;
				$currentDeviation = $priceDeviationMultiplierShort * $currentDeviation;
			}
		}
		
		/** Settings of the Long trades */
		$this->numberOfLevels = $numberOfLevels;
		$this->entryVolume = $entryVolume;
		$this->volumeMultiplier = $volumeMultiplier;
		$this->priceDeviation = $priceDeviation;
		$this->priceDeviationMultiplier = $priceDeviationMultiplier;
		$this->expectedProfit = $expectedProfit;

		/** Settings of the Short trades */
		$this->numberOfLevelsShort = $numberOfLevelsShort;
		$this->entryVolumeShort = $entryVolumeShort;
		$this->volumeMultiplierShort = $volumeMultiplierShort;
		$this->priceDeviationShort = $priceDeviationShort;
		$this->priceDeviationMultiplierShort = $priceDeviationMultiplierShort;
		$this->expectedProfitShort = $expectedProfitShort;
		$this->useLimitOrders = $useLimitOrders;
	}
	
	public function getOrderMap(): array {
		return $this->orderMap;
	}
	
	public function getMaxTotalPositionVolume(): Money {
		$totalVolume = 0.0;
		
		// Volumes for Long trades.
		foreach ($this->orderMap[PositionDirectionEnum::LONG->value] as $level) {
			$totalVolume += $level['volume'];
		}
		
		// Volumes for Short trades.
		foreach ($this->orderMap[PositionDirectionEnum::SHORT->value] as $level) {
			$totalVolume += $level['volume'];
		}
		
		return Money::from($totalVolume);
	}
	
	public function getMaxLongPositionVolume(): Money {
		$totalVolume = 0.0;
		foreach ($this->orderMap[PositionDirectionEnum::LONG->value] as $level) {
			$totalVolume += $level['volume'];
		}
		return Money::from($totalVolume);
	}
	
	public function getMaxShortPositionVolume(): Money {
		$totalVolume = 0.0;
		foreach ($this->orderMap[PositionDirectionEnum::SHORT->value] as $level) {
			$totalVolume += $level['volume'];
		}
		return Money::from($totalVolume);
	}

	public function getNumberOfLevels(): int {
		return $this->numberOfLevels;
	}

	public function getEntryVolume(): Money {
		return $this->entryVolume;
	}

	public function getVolumeMultiplier(): float {
		return $this->volumeMultiplier;
	}

	public function getPriceDeviation(): float {
		return $this->priceDeviation;
	}

	public function getPriceDeviationMultiplier(): float {
		return $this->priceDeviationMultiplier;
	}

	public function getExpectedProfit(): float {
		return $this->expectedProfit;
	}

	public function isUseLimitOrders(): bool {
		return $this->useLimitOrders;
	}
}

<?php

namespace Izzy\Strategies;
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
	 * Builds a DCASettings instance.
	 * @param int $numberOfLevels Number of DCA levels, including position entry.
	 * @param Money $entryVolume Initial position volume.
	 * @param float $volumeMultiplier How much should be the next order greater than the previous one.
	 * @param float $priceDeviation The distance between the entry and the first averaging.
	 * @param float $priceDeviationMultiplier How much further (in percent) should be the next order from the entry price.
	 * @param float $expectedProfit Expected profit in percents of the position size.
	 */
	public function __construct(
		int $numberOfLevels,
		Money $entryVolume,
		float $volumeMultiplier,
		float $priceDeviation,
		float $priceDeviationMultiplier,
		float $expectedProfit,
	) {
		$volume = $entryVolume->getAmount();
		$offset = 0;
		for ($level = 0; $level <= $numberOfLevels; $level++) {
			$this->orderMap[$level] = ['volume' =>  $volume, 'offset' => $offset];
			$volume *= $volumeMultiplier;
			$offset += $priceDeviation * ($level *  $priceDeviationMultiplier);
		}
		var_dump($this->orderMap);
		
		$this->numberOfLevels = $numberOfLevels;
		$this->entryVolume = $entryVolume;
		$this->volumeMultiplier = $volumeMultiplier;
		$this->priceDeviation = $priceDeviation;
		$this->priceDeviationMultiplier = $priceDeviationMultiplier;
		$this->expectedProfit = $expectedProfit;
	}
	
	public function getOrderMap(): array {
		return $this->orderMap;
	}
	
	public function getMaxTotalPositionVolume(): Money {
		return new Money(
			array_reduce(
				$this->orderMap,
				function ($carry, $item) { return $carry + $item['volume']; },
				0.0
			)
		);
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
}

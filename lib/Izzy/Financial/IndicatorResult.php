<?php

namespace Izzy\Financial;

/**
 * Result of indicator calculation.
 * Contains calculated values, timestamps, and signals.
 */
class IndicatorResult {
	/**
	 * Array of indicator values.
	 * @var array
	 */
	private array $values;

	/**
	 * Array of corresponding timestamps.
	 * @var array
	 */
	private array $timestamps;

	/**
	 * Array of signals (overbought/oversold conditions).
	 * @var array
	 */
	private array $signals;

	/**
	 * Constructor for indicator result.
	 *
	 * @param array $values Array of indicator values.
	 * @param array $timestamps Array of corresponding timestamps.
	 * @param array $signals Array of signals (optional).
	 */
	public function __construct(array $values, array $timestamps, array $signals = []) {
		$this->values = $values;
		$this->timestamps = $timestamps;
		$this->signals = $signals;
	}

	/**
	 * Get indicator values.
	 *
	 * @return array Array of indicator values.
	 */
	public function getValues(): array {
		return $this->values;
	}

	/**
	 * Get timestamps.
	 *
	 * @return array Array of timestamps.
	 */
	public function getTimestamps(): array {
		return $this->timestamps;
	}

	/**
	 * Get signals.
	 *
	 * @return array Array of signals.
	 */
	public function getSignals(): array {
		return $this->signals;
	}

	/**
	 * Get the latest indicator value.
	 *
	 * @return float|null Latest value or null if no values.
	 */
	public function getLatestValue(): ?float {
		return end($this->values) ?: null;
	}

	/**
	 * Get the latest timestamp.
	 *
	 * @return int|null Latest timestamp or null if no timestamps.
	 */
	public function getLatestTimestamp(): ?int {
		return end($this->timestamps) ?: null;
	}

	/**
	 * Get the latest signal.
	 *
	 * @return mixed Latest signal or null if no signals.
	 */
	public function getLatestSignal() {
		return end($this->signals) ?: null;
	}

	/**
	 * Get value count.
	 *
	 * @return int Number of values.
	 */
	public function getCount(): int {
		return count($this->values);
	}

	/**
	 * Check if result has values.
	 *
	 * @return bool True if has values, false otherwise.
	 */
	public function hasValues(): bool {
		return !empty($this->values);
	}
}

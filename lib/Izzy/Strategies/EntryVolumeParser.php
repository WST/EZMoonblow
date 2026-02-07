<?php

namespace Izzy\Strategies;

use Izzy\Enums\EntryVolumeModeEnum;

/**
 * Parses entry volume configuration values into mode and numeric value.
 *
 * Supported formats:
 * - "140" or "140 USDT" → ABSOLUTE_QUOTE, 140
 * - "5%" → PERCENT_BALANCE, 5
 * - "5%M" or "5% margin" → PERCENT_MARGIN, 5
 * - "0.002 BTC" or "2 SOL" → ABSOLUTE_BASE, 0.002/2
 */
class EntryVolumeParser
{
	/**
	 * Parsed numeric value.
	 * @var float
	 */
	private float $value;

	/**
	 * Parsed volume mode.
	 * @var EntryVolumeModeEnum
	 */
	private EntryVolumeModeEnum $mode;

	/**
	 * Original raw input.
	 * @var string
	 */
	private string $rawInput;

	/**
	 * Parse an entry volume configuration value.
	 *
	 * @param string|int|float $input Raw configuration value.
	 * @return self
	 */
	public static function parse(string|int|float $input): self {
		$parser = new self();
		$parser->rawInput = (string)$input;
		$parser->parseInput();
		return $parser;
	}

	/**
	 * Internal parsing logic.
	 */
	private function parseInput(): void {
		$input = trim($this->rawInput);

		// Check for percentage with margin modifier: "5%M" or "5% margin" or "5%m"
		if (preg_match('/^([\d.]+)\s*%\s*[Mm](argin)?$/i', $input, $matches)) {
			$this->value = (float)$matches[1];
			$this->mode = EntryVolumeModeEnum::PERCENT_MARGIN;
			return;
		}

		// Check for percentage of balance: "5%" or "5 %"
		if (preg_match('/^([\d.]+)\s*%$/', $input, $matches)) {
			$this->value = (float)$matches[1];
			$this->mode = EntryVolumeModeEnum::PERCENT_BALANCE;
			return;
		}

		// Check for USDT absolute value: "140 USDT" or "140USDT"
		if (preg_match('/^([\d.]+)\s*USDT$/i', $input, $matches)) {
			$this->value = (float)$matches[1];
			$this->mode = EntryVolumeModeEnum::ABSOLUTE_QUOTE;
			return;
		}

		// Check for base currency: "0.002 BTC", "2 SOL", "100 ETH", etc.
		// Matches: number followed by space and letters (currency code)
		if (preg_match('/^([\d.]+)\s+([A-Za-z]{2,10})$/i', $input, $matches)) {
			$currency = strtoupper($matches[2]);
			// USDT is handled above, everything else is base currency
			if ($currency !== 'USDT') {
				$this->value = (float)$matches[1];
				$this->mode = EntryVolumeModeEnum::ABSOLUTE_BASE;
				return;
			}
		}

		// Default: plain number is ABSOLUTE_QUOTE (backward compatibility)
		$this->value = (float)$input;
		$this->mode = EntryVolumeModeEnum::ABSOLUTE_QUOTE;
	}

	/**
	 * Get the parsed numeric value.
	 * @return float
	 */
	public function getValue(): float {
		return $this->value;
	}

	/**
	 * Get the parsed volume mode.
	 * @return EntryVolumeModeEnum
	 */
	public function getMode(): EntryVolumeModeEnum {
		return $this->mode;
	}

	/**
	 * Get the original raw input.
	 * @return string
	 */
	public function getRawInput(): string {
		return $this->rawInput;
	}

	/**
	 * Check if the mode requires runtime calculation.
	 * @return bool
	 */
	public function requiresRuntimeCalculation(): bool {
		return $this->mode->requiresRuntimeCalculation();
	}
}

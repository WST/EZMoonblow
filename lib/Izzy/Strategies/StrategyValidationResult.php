<?php

namespace Izzy\Strategies;

/**
 * Result of strategy exchange settings validation.
 *
 * Errors are critical — trading is impossible.
 * Warnings are informational — trading is possible but suboptimal.
 */
class StrategyValidationResult
{
	/** @var string[] Critical errors that prevent trading. */
	private array $errors = [];

	/** @var string[] Non-critical warnings about suboptimal settings. */
	private array $warnings = [];

	/**
	 * Add a critical error (trading will be blocked).
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	public function addError(string $message): void {
		$this->errors[] = $message;
	}

	/**
	 * Add a warning (trading is still possible but suboptimal).
	 *
	 * @param string $message Warning message.
	 * @return void
	 */
	public function addWarning(string $message): void {
		$this->warnings[] = $message;
	}

	/**
	 * Check if the validation passed (no errors).
	 *
	 * @return bool True if there are no errors.
	 */
	public function isValid(): bool {
		return empty($this->errors);
	}

	/**
	 * Get all critical errors.
	 *
	 * @return string[]
	 */
	public function getErrors(): array {
		return $this->errors;
	}

	/**
	 * Get all warnings.
	 *
	 * @return string[]
	 */
	public function getWarnings(): array {
		return $this->warnings;
	}

	/**
	 * Get all messages (errors + warnings).
	 *
	 * @return string[]
	 */
	public function getAllMessages(): array {
		return array_merge($this->errors, $this->warnings);
	}
}

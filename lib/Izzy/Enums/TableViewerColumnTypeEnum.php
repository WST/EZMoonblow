<?php

namespace Izzy\Enums;

/**
 * Column types for TableViewer.
 */
enum TableViewerColumnTypeEnum: string {
	case TEXT = 'text';
	case MONEY = 'money';
	case PERCENT = 'percent';
	case NUMBER = 'number';
	case HTML = 'html';
	case CUSTOM = 'custom';

	/**
	 * Indicates if the column type is text.
	 * @return bool
	 */
	public function isText(): bool {
		return $this === self::TEXT;
	}

	/**
	 * Indicates if the column type is money.
	 * @return bool
	 */
	public function isMoney(): bool {
		return $this === self::MONEY;
	}

	/**
	 * Indicates if the column type is percent.
	 * @return bool
	 */
	public function isPercent(): bool {
		return $this === self::PERCENT;
	}

	/**
	 * Indicates if the column type is number.
	 * @return bool
	 */
	public function isNumber(): bool {
		return $this === self::NUMBER;
	}

	/**
	 * Indicates if the column type is HTML.
	 * @return bool
	 */
	public function isHtml(): bool {
		return $this === self::HTML;
	}

	/**
	 * Indicates if the column type is custom.
	 * @return bool
	 */
	public function isCustom(): bool {
		return $this === self::CUSTOM;
	}

	/**
	 * Returns the string representation of the column type.
	 * @return string
	 */
	public function toString(): string {
		return $this->value;
	}
} 
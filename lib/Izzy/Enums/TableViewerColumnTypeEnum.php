<?php

namespace Izzy\Enums;

/**
 * Column types for TableViewer.
 */
enum TableViewerColumnTypeEnum: string
{
	case TEXT = 'text';
	case MONEY = 'money';
	case PERCENT = 'percent';
	case NUMBER = 'number';
	case INTEGER = 'integer';
	case DATE = 'date';
	case BADGE = 'badge';
	case MARKET_TYPE = 'market_type';
	case PNL = 'pnl';
	case HTML = 'html';
	case CUSTOM = 'custom';

	public function isText(): bool { return $this === self::TEXT; }
	public function isMoney(): bool { return $this === self::MONEY; }
	public function isPercent(): bool { return $this === self::PERCENT; }
	public function isNumber(): bool { return $this === self::NUMBER; }
	public function isInteger(): bool { return $this === self::INTEGER; }
	public function isDate(): bool { return $this === self::DATE; }
	public function isBadge(): bool { return $this === self::BADGE; }
	public function isMarketType(): bool { return $this === self::MARKET_TYPE; }
	public function isPnl(): bool { return $this === self::PNL; }
	public function isHtml(): bool { return $this === self::HTML; }
	public function isCustom(): bool { return $this === self::CUSTOM; }

	/**
	 * Whether the rendered value contains raw HTML (not to be escaped).
	 */
	public function rendersHtml(): bool {
		return match ($this) {
			self::HTML, self::CUSTOM, self::BADGE, self::MARKET_TYPE, self::PNL => true,
			default => false,
		};
	}

	public function toString(): string {
		return $this->value;
	}
}

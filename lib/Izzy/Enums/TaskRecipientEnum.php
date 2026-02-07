<?php

namespace Izzy\Enums;

enum TaskRecipientEnum: string
{
	case ANALYZER = 'Analyzer';
	case TRADER = 'Trader';
	case NOTIFIER = 'Notifier';

	public function isAnalyzer(): bool {
		return $this === self::ANALYZER;
	}

	public function isTrader(): bool {
		return $this === self::TRADER;
	}

	public function isNotifier(): bool {
		return $this === self::NOTIFIER;
	}
}

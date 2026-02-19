<?php

namespace Izzy\Enums;

enum TaskRecipientEnum: string
{
	case ANALYZER = 'Analyzer';
	case TRADER = 'Trader';
	case NOTIFIER = 'Notifier';
	case OPTIMIZER = 'Optimizer';

	public function isAnalyzer(): bool {
		return $this === self::ANALYZER;
	}

	public function isTrader(): bool {
		return $this === self::TRADER;
	}

	public function isNotifier(): bool {
		return $this === self::NOTIFIER;
	}

	public function isOptimizer(): bool {
		return $this === self::OPTIMIZER;
	}
}

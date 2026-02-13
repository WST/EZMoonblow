<?php

namespace Izzy\Enums;

enum PositionFinishReasonEnum: string
{
	/**
	 * The position was closed by a limit Take Profit order.
	 */
	case TAKE_PROFIT_LIMIT = 'TAKE_PROFIT_LIMIT';

	/**
	 * The position was closed by a limit Stop Loss order.
	 */
	case STOP_LOSS_LIMIT = 'STOP_LOSS_LIMIT';

	/**
	 * The position was closed by a market Take Profit order.
	 */
	case TAKE_PROFIT_MARKET = 'TAKE_PROFIT_MARKET';

	/**
	 * The position was closed by a market Stop Loss order.
	 */
	case STOP_LOSS_MARKET = 'STOP_LOSS_MARKET';

	/**
	 * The position was closed because the Exchange has liquidated the position.
	 */
	case LIQUIDATION = 'LIQUIDATION';

	/**
	 * Check if this reason is a take profit (limit or market).
	 */
	public function isTakeProfit(): bool {
		return $this === self::TAKE_PROFIT_LIMIT || $this === self::TAKE_PROFIT_MARKET;
	}

	/**
	 * Check if this reason is a stop loss (limit or market).
	 */
	public function isStopLoss(): bool {
		return $this === self::STOP_LOSS_LIMIT || $this === self::STOP_LOSS_MARKET;
	}

	/**
	 * Check if this reason is a liquidation.
	 */
	public function isLiquidation(): bool {
		return $this === self::LIQUIDATION;
	}

	/**
	 * Get a human-readable label for display in the UI.
	 */
	public function getLabel(): string {
		return match ($this) {
			self::TAKE_PROFIT_LIMIT => 'TP (Limit)',
			self::TAKE_PROFIT_MARKET => 'TP (Market)',
			self::STOP_LOSS_LIMIT => 'SL (Limit)',
			self::STOP_LOSS_MARKET => 'SL (Market)',
			self::LIQUIDATION => 'Liquidation',
		};
	}
}

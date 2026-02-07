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
}

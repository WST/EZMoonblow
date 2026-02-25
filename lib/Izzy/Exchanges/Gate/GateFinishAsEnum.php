<?php

namespace Izzy\Exchanges\Gate;

/**
 * Gate.io order finish_as values.
 */
enum GateFinishAsEnum: string
{
	case Filled = 'filled';

	case Cancelled = 'cancelled';

	case IOC = 'ioc';

	case ReduceOnly = 'reduce_only';

	case Liquidated = 'liquidated';

	case AutoDeleveraged = 'auto_deleveraged';

	case PositionClosed = 'position_closed';
}

<?php

namespace Izzy\Exchanges\Gate;

/**
 * Time-in-force values for Gate.io orders.
 */
enum GateOrderTifEnum: string
{
	/** Good till cancelled. */
	case GTC = 'gtc';

	/** Immediate or cancel (market orders). */
	case IOC = 'ioc';

	/** Pending or cancelled (post-only / maker). */
	case POC = 'poc';

	/** Fill or kill. */
	case FOK = 'fok';
}

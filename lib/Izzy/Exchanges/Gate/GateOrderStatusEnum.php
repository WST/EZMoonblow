<?php

namespace Izzy\Exchanges\Gate;

/**
 * Gate.io order status values.
 */
enum GateOrderStatusEnum: string
{
	case Open = 'open';

	case Finished = 'finished';
}

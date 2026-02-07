<?php

namespace Izzy\Exchanges\Bybit;

enum BybitTPTriggerByEnum: string
{
	case LastPrice = 'LastPrice';

	case IndexPrice = 'IndexPrice';

	case MarkPrice = 'MarkPrice';
}

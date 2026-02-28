<?php

namespace Izzy\Enums;

enum FilterFieldTypeEnum: string
{
	case MULTI_SELECT = 'multi_select';
	case SELECT = 'select';
	case NUMBER_INPUT = 'number_input';
	case DATE_CONDITION = 'date_condition';
}

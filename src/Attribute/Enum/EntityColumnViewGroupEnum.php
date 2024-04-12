<?php

namespace Dakataa\Crud\Attribute\Enum;

enum EntityColumnViewGroupEnum: string
{
	case List = 'List';
	case View = 'View';
	case Export = 'Export';
	case System = 'None';
}

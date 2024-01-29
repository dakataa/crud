<?php

namespace Dakataa\Crud\Attribute\Enum;

enum EntityColumnViewTypeEnum: string
{
	case List = 'List';
	case View = 'View';
	case Export = 'Export';
}

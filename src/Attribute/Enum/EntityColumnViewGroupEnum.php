<?php

namespace Dakataa\Crud\Attribute\Enum;

enum EntityColumnViewGroupEnum: string
{
	case List = 'list';
	case View = 'view';
	case Export = 'export';
	case System = 'none';
}

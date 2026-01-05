<?php

namespace Dakataa\Crud\Attribute\Enum;

enum ActionVisibilityEnum: string
{
	case List = 'list';
	case Object = 'object';
	case Internal = 'internal';
}

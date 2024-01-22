<?php

namespace Dakataa\Crud\Attribute\Enum;

use Attribute;
use Dakataa\Crud\Attribute\Navigation\NavigationItemInterface;

enum EntityColumnViewTypeEnum: string
{
    case List = 'List';
    case Export = 'Export';
}

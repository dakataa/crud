<?php

namespace Dakataa\Crud\Attribute\Resolver;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class QueryResolver extends AbstractActionResolver
{
}

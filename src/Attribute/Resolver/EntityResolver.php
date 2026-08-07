<?php

namespace Dakataa\Crud\Attribute\Resolver;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class EntityResolver extends AbstractActionResolver
{
}

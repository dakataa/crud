<?php

namespace Dakataa\Crud\Attribute\Resolver;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ResponseContextResolver extends AbstractActionResolver
{
}

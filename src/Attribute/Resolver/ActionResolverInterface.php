<?php

namespace Dakataa\Crud\Attribute\Resolver;

use Dakataa\Crud\Attribute\Action;

interface ActionResolverInterface extends ResolverInterface
{
	public function supports(Action $action): bool;
}

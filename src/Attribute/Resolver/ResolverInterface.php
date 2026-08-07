<?php

namespace Dakataa\Crud\Attribute\Resolver;

interface ResolverInterface
{
	public function getCallable(object $resolverContext): callable;
}

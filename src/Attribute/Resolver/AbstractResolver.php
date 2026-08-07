<?php

namespace Dakataa\Crud\Attribute\Resolver;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class AbstractResolver implements ResolverInterface
{
	/**
	 * @param string|array $resolver
	 */
	public function __construct(
		public string|array $resolver
	) {
	}

	public function getCallable(object $resolverContext): callable
	{
		$resolver = $this->resolver;

		return match (true) {
			is_string($resolver) && class_exists($resolver) && method_exists($resolver, '__invoke') => [new $resolver, '__invoke'],
			is_string($resolver) && method_exists($resolverContext, $resolver) => (new ReflectionMethod(
				$resolverContext,
				$resolver
			))->getClosure($resolverContext),
			is_callable($resolver) => $resolver,
			default => throw new NotFoundHttpException(sprintf(
				'Invalid %s. Class or Method not found.',
				preg_replace('/(?<!^)([A-Z])/', ' $1', (new ReflectionClass($this))->getShortName())
			)),
		};
	}
}

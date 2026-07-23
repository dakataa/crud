<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class QueryResolver
{


	/**
	 * @param string|array $resolver
	 * @param array|null $actions
	 */
	public function __construct(
		public string|array $resolver,
		public array|null $actions = null
	) {
	}


	public function getCallable(object $resolverContext, Action $action): callable|false
	{
		$resolver = $this->resolver;

		if ($this->actions && !in_array($action->name, $this->actions)) {
			return false;
		}

		return match (true) {
			is_string($resolver) && class_exists($resolver) && method_exists($resolver, '__invoke') => [new $resolver, '__invoke'],
			is_string($resolver) && method_exists($resolverContext, $resolver) => (new ReflectionMethod(
				$resolverContext,
				$resolver
			))->getClosure($resolverContext),
			is_callable($resolver) => $resolver,
			default => throw new NotFoundHttpException('Invalid Query Resolver. Class or Method not found.'),
		};
	}
}

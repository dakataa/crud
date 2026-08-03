<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ResponseContextResolver
{
	/**
	 * @param string|array $resolver
	 * @param array<string>|null $actions
	 */
	public function __construct(
		public string|array $resolver,
		public ?array $actions = null
	) {
	}

	public function supports(Action $action): bool
	{
		return !$this->actions || in_array($action->getName(), $this->actions, true);
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
			default => throw new NotFoundHttpException('Invalid Response Context Resolver. Class or Method not found.'),
		};
	}
}

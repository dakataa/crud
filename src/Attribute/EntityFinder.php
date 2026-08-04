<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class EntityFinder
{
	/**
	 * @param string|array $finder
	 * @param array<string>|null $actions
	 */
	public function __construct(
		public string|array $finder,
		public ?array $actions = null
	) {
	}

	public function supports(Action $action): bool
	{
		return !$this->actions || in_array($action->getName(), $this->actions, true);
	}

	public function getCallable(object $resolverContext): callable
	{
		$finder = $this->finder;

		return match (true) {
			is_string($finder) && class_exists($finder) && method_exists($finder, '__invoke') => [new $finder, '__invoke'],
			is_string($finder) && method_exists($resolverContext, $finder) => (new ReflectionMethod(
				$resolverContext,
				$finder
			))->getClosure($resolverContext),
			is_callable($finder) => $finder,
			default => throw new NotFoundHttpException('Invalid Entity Finder. Class or Method not found.'),
		};
	}
}

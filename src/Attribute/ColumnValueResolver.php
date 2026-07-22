<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Attribute(Attribute::TARGET_METHOD)]
class ColumnValueResolver
{


	/**
	 * @param string|array $resolver
	 * @param array|null $fields
	 */
	public function __construct(
		public string|array $resolver,
		public array|null $fields = null
	) {
	}


	public function getCallable(object $resolverContext, Column $column): array|string|false
	{
		$resolver = $this->resolver;

		if ($this->fields && !in_array($column->getField(), $this->fields)) {
			return false;
		}

		return match (true) {
			is_string($resolver) && class_exists($resolver) => [new $resolver, '__invoke'],
			is_string($resolver) && method_exists($resolverContext, $resolver) => [$resolverContext, $resolver],
			is_callable($resolver) => $resolver,
			default => throw new NotFoundHttpException('Invalid Column Value Resolver. Class or Method not found.'),
		};
	}
}

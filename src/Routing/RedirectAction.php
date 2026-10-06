<?php

namespace Dakataa\Crud\Routing;

final class RedirectAction
{
	/**
	 * @param array<string, mixed> $parameters
	 */
	public function __construct(
		public readonly string $actionName,
		public readonly array $parameters = [],
	) {
	}
}

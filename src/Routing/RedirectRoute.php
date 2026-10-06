<?php

namespace Dakataa\Crud\Routing;

final class RedirectRoute
{
	/**
	 * @param array<string, mixed> $parameters
	 */
	public function __construct(
		public readonly string $routeName,
		public readonly array $parameters = [],
	) {
	}
}

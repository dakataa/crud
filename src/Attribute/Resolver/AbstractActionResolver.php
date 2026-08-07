<?php

namespace Dakataa\Crud\Attribute\Resolver;

use Dakataa\Crud\Attribute\Action;

abstract class AbstractActionResolver extends AbstractResolver implements ActionResolverInterface
{
	/**
	 * @param string|array $resolver
	 * @param array<string>|null $actions
	 */
	public function __construct(
		string|array $resolver,
		public ?array $actions = null
	) {
		parent::__construct($resolver);
	}

	public function supports(Action $action): bool
	{
		return !$this->actions || in_array($action->getName(), $this->actions, true);
	}
}

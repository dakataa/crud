<?php

namespace Dakataa\Crud\Service;

use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\RouterInterface;

class ActionCollection
{
	protected array $items;

	public function __construct(
		#[TaggedLocator('dakataa.crud.action')]
		private ServiceLocator $handlers,
		protected RouterInterface $router
	) {
		$this->items = $this->handlers->getProvidedServices();
	}

	public function getItems(): array
	{
		return $this->items;
	}
}

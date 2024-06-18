<?php

namespace Dakataa\Crud\Service;

use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\RouterInterface;

class RouteCollection
{
	protected array $items;

	public function __construct(
		#[AutowireLocator('dakataa.crud.entity')]
		private readonly ServiceLocator $handlers,
		protected RouterInterface $router
	) {
		$this->items = [];
		$loader = new AttributeRouteControllerLoader;
		foreach ($this->handlers->getProvidedServices() as $serviceFQCN) {
			$collection = $loader->load($serviceFQCN);
			$this->items = array_merge($this->items, array_values($collection->all()));
		}
	}

	public function getItems(): array
	{
		return $this->items;
	}
}

<?php

namespace Dakataa\Crud\Service;

use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\RouterInterface;

class RouteCollection
{
	protected array $items;

	public function __construct(
		#[TaggedLocator('dakataa.crud.entity')]
		private ServiceLocator $handlers,
		protected RouterInterface $router
	) {
		$this->items = [];
		foreach ($this->handlers->getProvidedServices() as $serviceFQCN) {
			$reflectionClass = new ReflectionClass($serviceFQCN);

			$loader = new AttributeRouteControllerLoader();
			$collection = $loader->load($reflectionClass->name);
			foreach (array_keys($collection->all()) as $routeName) {
				$this->items[] = $this->router->getRouteCollection()->get($routeName);
			}
		}
	}

	public function getItems(): array
	{
		return $this->items;
	}
}

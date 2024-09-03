<?php

namespace Dakataa\Crud\Service;

use Dakataa\Crud\Loader\ActionAttributeLoader;
use Generator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\RouterInterface;

class ActionCollection
{

	public function __construct(
		#[AutowireLocator('dakataa.crud.entity')]
		private readonly ServiceLocator $handlers,
		protected RouterInterface $router
	) {
	}

	public function getItems(): Generator
	{
		$loader = new ActionAttributeLoader;
		foreach ($this->handlers->getProvidedServices() as $serviceFQCN) {
			foreach($loader->load($serviceFQCN) as $item) {
				yield $item;
			}
		}
	}
}

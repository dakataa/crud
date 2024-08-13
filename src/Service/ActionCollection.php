<?php

namespace Dakataa\Crud\Service;

use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Loader\ActionAttributeLoader;
use Generator;
use ReflectionClass;
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
			if (!preg_match('/^.*\\\(?<name>.*)Controller$/i', $serviceFQCN, $matches)) {
				throw new \Exception('Invalid Service FQCN');
			}


			yield lcfirst($matches['name']) => $loader->load($serviceFQCN);
		}
	}
}

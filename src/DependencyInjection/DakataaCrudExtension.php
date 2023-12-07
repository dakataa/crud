<?php

namespace Dakataa\Crud\DependencyInjection;

use Dakataa\Crud\Twig\Extension\CrudExtension;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

class DakataaCrudExtension extends Extension
{
	public function getConfiguration(array $config, ContainerBuilder $container): ?ConfigurationInterface
	{
		return new Configuration();
	}

	public function load(array $configs, ContainerBuilder $container): void
	{
		$container
			->register(CrudExtension::class, CrudExtension::class)
			->addTag('twig.extension')
			->setAutowired(true);
	}
}

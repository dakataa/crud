<?php

namespace Dakataa\Crud\DependencyInjection;

use Dakataa\Crud\Attribute\Navigation\NavigationGroup;
use Dakataa\Crud\Attribute\Navigation\NavigationItem;
use Dakataa\Crud\Twig\Extension\NavigationExtension;
use ReflectionClass;
use Dakataa\Crud\Service\Navigation;
use Dakataa\Crud\DakataaCrudBundle;
use Dakataa\Crud\Twig\Extension\CrudExtension;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
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
		$container->registerAttributeForAutoconfiguration(
			NavigationItem::class,
			static function (
				ChildDefinition $definition,
				NavigationItem $attribute,
				ReflectionClass $reflector
			): void {
				$definition->addTag('dakataa.crud.navigation');
			}
		);

		$container->registerAttributeForAutoconfiguration(
			NavigationGroup::class,
			static function (
				ChildDefinition $definition,
				NavigationGroup $attribute,
				ReflectionClass $reflector
			): void {
				$definition->addTag('dakataa.crud.navigation');
			}
		);

		$container
			->register(CrudExtension::class, CrudExtension::class)
			->addTag('twig.extension')
			->setAutowired(true);

		$container
			->register(NavigationExtension::class, NavigationExtension::class)
			->addTag('twig.extension')
			->setAutowired(true);

		$container
			->register(Navigation::class, Navigation::class)
			->setAutowired(true);

		$container
			->setParameter(DakataaCrudBundle::NAME, array_shift($configs));
	}
}

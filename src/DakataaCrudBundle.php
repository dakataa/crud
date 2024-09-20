<?php

namespace Dakataa\Crud;

use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\Navigation\NavigationGroup;
use Dakataa\Crud\Attribute\Navigation\NavigationItem;
use Dakataa\Crud\Controller\GeneralController;
use Dakataa\Crud\EventSubscriber\CrudSubscriber;
use Dakataa\Crud\Service\ActionCollection;
use Dakataa\Crud\Service\Navigation;
use Dakataa\Crud\Service\RouteCollection;
use ReflectionClass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class DakataaCrudBundle extends AbstractBundle
{
	const NAME = 'dakataa_crud';

	public function configure(DefinitionConfigurator $definition): void
	{
		$definition
			->rootNode()
			->children()
			->variableNode('layout')//->isRequired()
			->end()
			->end();
	}

	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
		$container
			->services()
			->set(Navigation::class, Navigation::class)
			->autowire()
			->autoconfigure();

		$container
			->services()
			->set(RouteCollection::class, RouteCollection::class)
			->autowire()
			->autoconfigure();

		$container
			->services()
			->set(ActionCollection::class, ActionCollection::class)
			->autowire()
			->autoconfigure();

		$container
			->services()
			->set(CrudSubscriber::class, CrudSubscriber::class)
			->tag('controller.service_arguments')
			->autowire()
			->autoconfigure();

		$container
			->services()
			->set(GeneralController::class, GeneralController::class)
			->tag('controller.service_arguments')
			->autowire()
			->autoconfigure();

		$container->parameters()->set(self::NAME, $config);

		$builder->registerAttributeForAutoconfiguration(
			Entity::class,
			static function (
				ChildDefinition $definition,
				Entity $attribute,
				ReflectionClass $reflector
			): void {
				$definition->addTag('dakataa.crud.entity');
			}
		);

		$builder->registerAttributeForAutoconfiguration(
			NavigationItem::class,
			static function (
				ChildDefinition $definition,
				NavigationItem $attribute,
				ReflectionClass $reflector
			): void {
				$definition->addTag('dakataa.crud.navigation');
			}
		);

		$builder->registerAttributeForAutoconfiguration(
			NavigationGroup::class,
			static function (
				ChildDefinition $definition,
				NavigationGroup $attribute,
				ReflectionClass $reflector
			): void {
				$definition->addTag('dakataa.crud.navigation');
			}
		);
	}

	public function prependExtension(
		ContainerConfigurator $container,
		ContainerBuilder $builder
	): void {
		$container->import($this->getPath().'/config/{routes}/*.{php,yaml}');
	}
}

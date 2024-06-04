<?php

namespace Dakataa\Crud;

use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\Navigation\NavigationGroup;
use Dakataa\Crud\Attribute\Navigation\NavigationItem;
use Dakataa\Crud\Controller\GeneralController;
use Dakataa\Crud\EventSubscriber\CrudSubscriber;
use Dakataa\Crud\Service\ActionCollection;
use Dakataa\Crud\Service\Navigation;
use Dakataa\Crud\Twig\Extension\CrudExtension;
use Dakataa\Crud\Twig\Extension\NavigationExtension;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Dakataa\Crud\Service\RouteCollection;

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
//		$loader = new AttributeRouteControllerLoader;
//		$loader->load(NavigationController::class);

		$container
			->services()
			->set(CrudExtension::class, CrudExtension::class)
			->tag('twig.extension')
			->autowire();

		$container
			->services()
			->set(NavigationExtension::class, NavigationExtension::class)
			->tag('twig.extension')
			->autowire();

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
			->set(RouteCollection::class, ActionCollection::class)
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

		$builder->registerAttributeForAutoconfiguration(
			Action::class,
			static function (
				ChildDefinition $definition,
				Action $attribute,
				ReflectionClass $reflector
			): void {
				$definition->addTag('dakataa.crud.action');
			}
		);

	}

	public function prependExtension(
		ContainerConfigurator $container,
		ContainerBuilder $builder
	): void {
		$builder->prependExtensionConfig('webpack_encore', [
			'builds' => [
				self::NAME => '%kernel.project_dir%/public/bundles/dakataacrud/assets',
			],
		]);
	}
}

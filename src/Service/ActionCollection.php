<?php

namespace Dakataa\Crud\Service;

use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Utils\StringHelper;
use Generator;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ActionCollection
{
	public function __construct(
		#[AutowireLocator('dakataa.crud.entity')]
		private readonly ServiceLocator $handlers,
		protected RouterInterface $router,
		protected AuthorizationCheckerInterface $authorizationChecker
	) {
	}

	public function getAll(): Generator
	{
		foreach ($this->handlers->getProvidedServices() as $serviceFQCN) {
			foreach ($this->load($serviceFQCN) as $item) {
				yield $item;
			}
		}
	}

	public function load(string $controllerFQCN, ?string $entityFCQN = null, ?string $method = null): Generator
	{
		if (!class_exists($controllerFQCN)) {
			throw new InvalidArgumentException(sprintf('Class "%s" does not exist.', $controllerFQCN));
		}

		$namespace = explode('\\', $controllerFQCN);
		$namespace = implode(
			'/',
			array_map(fn(string $item) => lcfirst(preg_replace('/Controller$/i', '', $item)),
				array_splice($namespace, 2))
		);

		$controllerReflectionClass = new ReflectionClass($controllerFQCN);
		$controllerEntityFQCN = ($controllerReflectionClass->getAttributes(Entity::class)[0] ?? null)?->getArguments()[0] ?? null;
		if(null === $controllerEntityFQCN) {
			return;
		}

		$isAccessGranted = function (ReflectionClass|ReflectionMethod $reflection): bool {
			/** @var IsGranted[] $isGrantedAttributes */
			$isGrantedAttributes = array_map(
				fn(ReflectionAttribute $refAttribute) => $refAttribute->newInstance(),
				$reflection->getAttributes(IsGranted::class)
			);

			foreach ($isGrantedAttributes as $isGrantedAttribute) {
				if (!$this->authorizationChecker->isGranted($isGrantedAttribute->attribute, $isGrantedAttribute->subject)) {
					return false;
				}
			}

			return true;
		};

		if(!$isAccessGranted($controllerReflectionClass)) {
			return;
		}

		$controllerReplacementActions = $controllerReflectionClass->getAttributes(Action::class);

		foreach ($controllerReflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
			$methodEntityFQCN = (($reflectionMethod->getAttributes(Entity::class)[0] ?? null)?->getArguments()[0] ?? null) ?: $controllerEntityFQCN;
			if(null === $methodEntityFQCN) {
				continue;
			}

			if($entityFCQN && $entityFCQN !== $methodEntityFQCN) {
				continue;
			}

			if($method && $method !== $reflectionMethod->name) {
				continue;
			}

			if(!$isAccessGranted($reflectionMethod)) {
				continue;
			}

			$entity = lcfirst((new ReflectionClass($methodEntityFQCN))->getShortName());

			/** @var Route $routeAttribute */
			$routeAttribute = ($reflectionMethod->getAttributes(Route::class)[0] ?? null)?->newInstance();

			$routeName = $routeAttribute?->getName() ?: ($controllerReflectionClass->name.'::'.$reflectionMethod->name);
			if(null !== $route = $this->router->getRouteCollection()->get($routeName)) {
				$routeAttribute = new Route($route->getPath(), $routeName, methods: $route->getMethods());
			}

			foreach ($reflectionMethod->getAttributes(Action::class) as $reflectionAttribute) {
				/** @var Action $actionInstance */
				$actionInstance = $reflectionAttribute->newInstance();
				$name = $actionInstance->name ?: $reflectionMethod->name;
				$replacementActionReflectionAttribute = array_values(
					array_filter(
						$controllerReplacementActions,
						fn(ReflectionAttribute $refAttribute) => $refAttribute->getArguments()[0] === $name
					)
				)[0] ?? null;

				if($replacementActionReflectionAttribute) {
					$actionInstance = $replacementActionReflectionAttribute->newInstance();
				}

				$title = ($actionInstance->title ?: StringHelper::titlize(ucfirst($name ?: $reflectionMethod->name)));

				yield $actionInstance
					->setName($name)
					->setMethod($reflectionMethod->name)
					->setTitle($title)
					->setRoute($routeAttribute)
					->setEntity($entity)
					->setNamespace($namespace);
			}
		}
	}

}

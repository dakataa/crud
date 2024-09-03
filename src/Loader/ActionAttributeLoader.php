<?php

namespace Dakataa\Crud\Loader;


use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Utils\StringHelper;
use Exception;
use Generator;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Annotation\Route;

class ActionAttributeLoader
{

	public function load(mixed $controllerFQCN, ?string $attribute = null): Generator
	{
		if (!class_exists($controllerFQCN)) {
			throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $controllerFQCN));
		}

		$controller = explode('\\', $controllerFQCN);
		$controller = implode('/', array_map(fn(string $item) => lcfirst(preg_replace('/Controller$/i', '', $item)), array_splice($controller, 2)));

		$controllerReflectionClass = new ReflectionClass($controllerFQCN);
		$entity = lcfirst((new ReflectionClass($controllerReflectionClass->getAttributes(Entity::class)[0]->newInstance()->fqcn))->getShortName());

		foreach ($controllerReflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
			/** @var Route $routeAttribute */
			$routeAttribute = ($reflectionMethod->getAttributes(
				Route::class
			)[0] ?? null)?->newInstance();

			foreach ($reflectionMethod->getAttributes(Action::class) as $reflectionAttribute) {
				/** @var Action $actionInstance */
				$actionInstance = $reflectionAttribute->newInstance();
				$name = ($actionInstance->name ?: $reflectionMethod->name);
				$title = ($actionInstance->name ?: StringHelper::titlize(
					ucfirst($reflectionMethod->name)
				));
				$routeName = $routeAttribute?->getName() ?: ($controllerReflectionClass->name.'::'.$reflectionMethod->name);

				$actionInstance
					->setName($name)
					->setTitle($title)
					->setRoute($routeName)
					->setEntity($entity)
					->setNamespace($controller);

				yield $actionInstance;
			}
		}
	}
}

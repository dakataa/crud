<?php

namespace Dakataa\Crud\Loader;


use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Utils\StringHelper;
use Generator;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Annotation\Route;

class ActionAttributeLoader {

	public function load(mixed $fqcn, ?string $attribute = null): Generator
	{
		if (!class_exists($fqcn)) {
			throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $fqcn));
		}

		$reflectionClass = new ReflectionClass($fqcn);
		foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
			/** @var Route $routeAttribute */
			$routeAttribute = ($reflectionMethod->getAttributes(
				Route::class
			)[0] ?? null)?->newInstance();

			foreach ($reflectionMethod->getAttributes(Action::class) as $reflectionAttribute) {
				$actionInstance = $reflectionAttribute->newInstance();
				$action = ($actionInstance->action ?: $reflectionMethod->name);
				$title = ($actionInstance->action ?: StringHelper::titlize(
					ucfirst($reflectionMethod->name)
				));
				$routeName = $routeAttribute?->getName(
				) ?: ($reflectionClass->name.'::'.$reflectionMethod->name);

				$actionInstance
					->setAction($action)
					->setTitle($title)
					->setRoute($routeName);

				yield $actionInstance;
			}
		}
	}
}

<?php

namespace Dakataa\Crud\Twig\Extension;

use Dakataa\Crud\DakataaCrudBundle;
use Dakataa\Crud\EventSubscriber\CrudSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class CrudExtension extends AbstractExtension
{

	public function __construct(
		protected RequestStack $requestStack,
		protected EntityManagerInterface $entityManager,
		protected RouterInterface $router,
		protected ParameterBagInterface $parameterBag,
		protected CrudSubscriber $crudSubscriber
	) {
	}

	public function getFunctions(): array
	{
		return [
			new TwigFunction('hasAction', [$this, 'hasAction']),
			new TwigFunction('generatePath', [$this, 'generatePathForAction']),
			new TwigFunction('getRoute', [$this, 'getRoute']),
			new TwigFunction('entityPrimaryKey', [$this, 'entityPrimaryKey']),
			new TwigFunction('controllerClass', [$this, 'getControllerClass']),
			new TwigFunction('getParameter', [$this, 'getParameter']),
		];
	}

	public function getFilters(): array
	{
		return [
			new TwigFilter('entityPrimaryKey', [$this, 'entityPrimaryKey']),
		];
	}

	public function entityPrimaryKey(object $entity): mixed
	{
		return $this->entityManager->getClassMetadata(get_class($entity))->getSingleIdReflectionProperty()->getValue(
			$entity
		);
	}

	public function getRoute(string $method, string $controllerFQCN = null)
	{
		$controllerFQCN ??= $this->getControllerClass();
		$mappedRoutes = $this->crudSubscriber->getController()?->getActions();

		return ($mappedRoutes[$method] ?? null)?->getRoute() ?? ($controllerFQCN.'::'.$method);
	}

	public function hasAction(string $method): bool
	{
		$mappedRoutes = $this->crudSubscriber->getController()?->getActions();

		return isset($mappedRoutes[$method]);
	}

	public function generatePathForAction(): string
	{
		$arguments = func_get_args();
		$isClassPassed = class_exists($arguments[0]);
		$controllerFqcn = $isClassPassed ? $arguments[0] : $this->getControllerClass();
		$method = ($isClassPassed ? $arguments[1] : $arguments[0]);
		$parameters = ($isClassPassed ? ($arguments[2] ?? []) : ($arguments[1] ?? []));

		if ($parameters && !is_array($parameters)) {
			throw new Exception('Invalid Argument "$parameters" its should be array.');
		}

		$requestAttributes = $this->requestStack->getMainRequest()->attributes;
		$pathParameters = array_intersect_key($requestAttributes->all(), array_flip(array_filter($requestAttributes->keys(), fn(string $key) => !str_starts_with($key, '_'))));

		return $this->router->generate($this->getRoute($method, $controllerFqcn), array_merge($pathParameters, $parameters));
	}

	public function getControllerClass(): string
	{
		return explode('::', $this->requestStack->getMainRequest()->attributes->get('_controller'))[0];
	}

	public function getParameter(string $key): mixed
	{
		return $this->parameterBag->get(DakataaCrudBundle::NAME)[$key] ?? null;
	}
}

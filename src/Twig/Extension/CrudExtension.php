<?php

namespace Dakataa\Crud\Twig\Extension;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
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
		protected RouterInterface $router
	) {
	}

	public function getFunctions(): array
	{
		return [
			new TwigFunction('generatePath', [$this, 'generatePathForAction']),
			new TwigFunction('getRoute', [$this, 'getRoute']),
			new TwigFunction('entityPrimaryKey', [$this, 'entityPrimaryKey']),
			new TwigFunction('controllerClass', [$this, 'getControllerClass']),
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
		return $this->entityManager->getClassMetadata(get_class($entity))->getSingleIdReflectionProperty()->getValue($entity);
	}

	public function getRoute(string $method, string $fqcn = null) {
		$fqcn ??= $this->getControllerClass();

		return $fqcn.'::'.$method;
	}

	public function generatePathForAction(): string
	{
		$arguments = func_get_args();
		$isClassPassed = class_exists($arguments[0]);
		$class = $isClassPassed ? $arguments[0] : $this->getControllerClass();
		$method = ($isClassPassed ? $arguments[1] : $arguments[0]);
		$parameters = ($isClassPassed ? ($arguments[2] ?? []) : ($arguments[1] ?? []));

		if ($parameters && !is_array($parameters)) {
			throw new Exception('Invalid Argument "$parameters" its should be array.');
		}

		return $this->router->generate($class.'::'.$method, $parameters);
	}

	public function getControllerClass(): string
	{
		return explode('::', $this->requestStack->getMainRequest()->attributes->get('_controller'))[0];
	}
}

<?php

namespace Dakataa\Crud\Controller;

use Dakataa\Crud\Serializer\Normalizer\ActionNormalizer;
use Dakataa\Crud\Serializer\Normalizer\NavigationNormalizer;
use Dakataa\Crud\Serializer\Normalizer\RouteNormalizer;
use Dakataa\Crud\Service\ActionCollection;
use Dakataa\Crud\Service\Navigation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Serializer;

#[AsController]
class GeneralController
{
	public function __construct(protected RouterInterface $router) {

	}

	#[Route('/navigation')]
	public function navigation(Navigation $navigation): JsonResponse
	{
		$serializer = new Serializer([
			new NavigationNormalizer($this->router),
			new RouteNormalizer,
		]);

		return new JsonResponse(
			$serializer->normalize($navigation->getItems(), context: [
				AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => fn() => null,
			])
		);
	}

	#[Route('/actions')]
	public function actions(ActionCollection $actionCollection, RouterInterface $router): JsonResponse
	{
		$serializer = new Serializer([
			new ActionNormalizer($router),
			new RouteNormalizer
		]);

		return new JsonResponse(
			$serializer->normalize(iterator_to_array($actionCollection->getAll()), context: [
				AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => fn() => null,
			])
		);
	}
}

<?php

namespace Dakataa\Crud\Controller;

use Dakataa\Crud\Serializer\Normalizer\ActionNormalizer;
use Dakataa\Crud\Serializer\Normalizer\ColumnNormalizer;
use Dakataa\Crud\Serializer\Normalizer\FormErrorNormalizer;
use Dakataa\Crud\Serializer\Normalizer\FormViewNormalizer;
use Dakataa\Crud\Serializer\Normalizer\NavigationNormalizer;
use Dakataa\Crud\Serializer\Normalizer\RouteNormalizer;
use Dakataa\Crud\Service\Navigation;
use Dakataa\Crud\Service\RouteCollection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
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

	#[Route('/routes')]
	public function routes(RouteCollection $routeCollection): JsonResponse
	{
		$serializer = new Serializer([
			new RouteNormalizer,
		]);

		return new JsonResponse(
			$serializer->normalize($routeCollection->getItems(), context: [
				AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => fn() => null,
			])
		);
	}
}

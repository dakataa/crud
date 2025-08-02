<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Dakataa\Crud\Attribute\Action;
use Exception;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ActionNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
	use NormalizerAwareTrait;

	public function __construct(protected RouterInterface $router) {

	}

	/**
	 * @param Action $object
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 * @throws ExceptionInterface
	 */
	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		$routeName = $object->getRoute()?->getName();
		if (!$routeName) {
			throw new Exception('Cannot normalize Action because of missing Route Name. Please check routes.yaml');
		}

		$route = $this->router->getRouteCollection()->get($routeName);
		return [
			'entity' => $object->getEntity(),
			'namespace' => $object->getNamespace(),
			'name' => $object->getName(),
			'title' => $object->getTitle(),
			'visibility' => $object->getVisibility(),
			'route' => $this->normalizer->normalize($route)
		];
	}

	public function getSupportedTypes(?string $format): array
	{
		return [
			Action::class => false,
		];
	}

	public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
	{
		return $data instanceof Action;
	}
}

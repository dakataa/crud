<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Dakataa\Crud\Attribute\Action;
use Symfony\Component\Routing\RouterInterface;
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
	 */
	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		$route = $this->router->getRouteCollection()->get($object->getRoute());
		return [
			'name' => $object->getName(),
			'title' => $object->getTitle(),
			'object' => $object->getObject(),
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

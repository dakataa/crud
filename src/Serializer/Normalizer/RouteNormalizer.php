<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Dakataa\Crud\Attribute\Column;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class RouteNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
	use NormalizerAwareTrait;

	/**
	 * @param Route $object
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 */
	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		return [
			'path' => $object->getPath(),
			'method' => $object->getMethods(),
			'variables' => $object->compile()->getVariables(),
			'defaults' => array_intersect_key($object->getDefaults(), array_flip($object->compile()->getVariables())),
			'requirements' => $object->getRequirements()
		];
	}

	public function getSupportedTypes(?string $format): array
	{
		return [
			Route::class => true,
		];
	}

	public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
	{
		return $data instanceof Route;
	}
}

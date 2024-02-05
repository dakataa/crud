<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Dakataa\Crud\Attribute\Column;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormView;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class FormErrorNormalizer implements NormalizerInterface
{
	/**
	 * @param FormErrorIterator $object
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 */
	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		foreach ($object as $formError) {
			dd(1);
		}
		return [
			'message' => $object->getMessage(),
			'cause' => $object->getCause(),
		];
	}

	public function getSupportedTypes(?string $format): array
	{
		return [
			FormErrorIterator::class => false,
		];
	}

	public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
	{
		return $data instanceof FormErrorIterator;
	}
}

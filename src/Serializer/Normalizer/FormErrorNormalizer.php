<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Symfony\Component\Form\FormError;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class FormErrorNormalizer implements NormalizerInterface
{
	/**
	 * @param FormError $data
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 */
	public function normalize(mixed $data, ?string $format = null, array $context = []): array
	{
		$names = [$data->getOrigin()->getName()];
		$parent = $data->getOrigin()->getParent();
		while($parent) {
			$names[] = $parent->getName();
			$parent = $parent->getParent();
		}
		$names = array_reverse($names);
		$origin = array_shift($names) . implode('', array_map(fn($n) => '[' . $n . ']', $names));
		return [
			'origin' => $origin,
			'message' => $data->getMessage(),
			'messageTemplate' => $data->getMessageTemplate(),
			'messageParameters' => $data->getMessageParameters()
		];
	}

	public function getSupportedTypes(?string $format): array
	{
		return [
			FormError::class => true,
		];
	}

	public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
	{
		return $data instanceof FormError;
	}
}

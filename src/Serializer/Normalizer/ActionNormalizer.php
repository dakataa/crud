<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Dakataa\Crud\Attribute\Action;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ActionNormalizer implements NormalizerInterface
{
	/**
	 * @param Action $object
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 */
	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		return [
			'action' => $object->getAction(),
			'title' => $object->getTitle(),
			'object' => $object->getObject(),
			'url' => null
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

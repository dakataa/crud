<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\SearchableOptions;
use Symfony\Component\Form\FormView;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ColumnNormalizer implements NormalizerInterface
{
	/**
	 * @param Column $object
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 */
	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		return [
			'field' => $object->getField(),
			'label' => $object->getLabel(),
			'options' => $object->getOptions(),
			'searchable' => $object->getSearchable() instanceof SearchableOptions ? [
				'options' => $object->getSearchable()->getOptions()
			] : $object->getSearchable()
		];
	}

	public function getSupportedTypes(?string $format): array
	{
		return [
			Column::class => false,
		];
	}

	public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
	{
		return $data instanceof Column;
	}
}

<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewGroupEnum;
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
			'sortable' => $object->getSortable(),
			'searchable' => $object->getSearchable() !== false,
			'identifier' => $object->isIdentifier(),
			'group' => $object->getGroup() instanceof EntityColumnViewGroupEnum ? $object->getGroup()->name : $object->getGroup(),
		];
	}

	public function getSupportedTypes(?string $format): array
	{
		return [
			Column::class => true,
		];
	}

	public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
	{
		return $data instanceof Column;
	}
}

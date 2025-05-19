<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewGroupEnum;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ColumnNormalizer implements NormalizerInterface
{
	/**
	 * @param Column $data
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 */
	public function normalize(mixed $data, ?string $format = null, array $context = []): array
	{
		return [
			'field' => $data->getField(),
			'label' => $data->getLabel(),
			'options' => $data->getOptions(),
			'sortable' => $data->getSortable(),
			'searchable' => $data->getSearchable() !== false,
			'identifier' => $data->isIdentifier(),
			'group' => $data->getGroup() instanceof EntityColumnViewGroupEnum ? $data->getGroup()->name : $data->getGroup(),
			'visible' => $data->isVisible(),
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

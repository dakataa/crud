<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormView;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class FormViewNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
	use NormalizerAwareTrait;

	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		/**
		 * @var FormErrorIterator $errors
		 */
		['block_prefixes' => $blockPrefixes, 'errors' => $errors] = $object->vars;

		return [
			'type' => array_slice($blockPrefixes, -2, 1)[0] ?? 'form',
			'errors' => $this->normalizer->normalize($errors),
			...array_intersect_key(
				$object->vars,
				array_flip([
					'id',
					'attr',
					'name',
					'full_name',
					'label',
					'data',
					'disabled',
					'required',
					'priority',
					'valid',
					'choices',
					'preferred_choices',
					'placeholder',
					'placeholder_attr',
					'placeholder_in_choices',
					'method',
					'submitted',
				])
			),
			'children' => $this->normalizer->normalize($object->children),
		];
	}

	public function getSupportedTypes(?string $format): array
	{
		return [
			FormView::class => false,
		];
	}

	public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
	{
		return $data instanceof FormView;
	}
}

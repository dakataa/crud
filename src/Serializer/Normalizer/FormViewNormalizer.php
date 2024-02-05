<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormView;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class FormViewNormalizer implements NormalizerInterface
{
	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		$formSerializer = function (FormView $formView) use (&$formSerializer) {
			/**
			 * @var FormErrorIterator $errors
			 */
			['block_prefixes' => $blockPrefixes, 'errors' => $errors] = $formView->vars;

			return [
				'type' => array_slice($blockPrefixes, -2, 1)[0] ?? 'form',
				'errors' => array_map(fn(FormError $error) => [
					'message' => $error->getMessage(),
					'messageTemplate' => $error->getMessageTemplate(),
					'messageParameters' => $error->getMessageParameters()
				], iterator_to_array($errors)),
				...array_intersect_key($formView->vars, array_flip([
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
					'submitted'
				])),
				'children' => array_map(fn(FormView $child) => $formSerializer($child), $formView->children)
			];
		};

		return $formSerializer($object);
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

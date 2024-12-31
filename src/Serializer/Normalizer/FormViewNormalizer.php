<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormView;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class FormViewNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
	use NormalizerAwareTrait;

	/**
	 * @param FormView|null $object
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
	 */
	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		/**
		 * @var FormErrorIterator $errors
		 */
		['block_prefixes' => $blockPrefixes, 'errors' => $errors, 'data' => $data, 'choices' => $choices] = $object->vars + ['choices' => null];

		$data = ($choices ? array_values(array_map(fn(ChoiceView $c) => $c->value, array_filter($choices, fn(ChoiceView $c) => in_array($c->data, $data instanceof ArrayCollection ? $data->getValues() : (is_array($data) ? $data : [$data]), true)))) ?: null : $data);

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
					'label_attr',
					'label_html',
					'help',
					'help_attr',
					'help_html',
					'data',
					'disabled',
					'required',
					'priority',
					'valid',
					'choices',
					'choice_attr',
					'preferred_choices',
					'placeholder',
					'placeholder_attr',
					'placeholder_in_choices',
					'method',
					'submitted',
					'checked',
					'expanded',
					'multiple'
				])
			),
			'data' => $data,
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

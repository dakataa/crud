<?php

namespace Dakataa\Crud\Serializer\Normalizer;

use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewGroupEnum;
use Dakataa\Crud\Attribute\Navigation\NavigationGroup;
use Dakataa\Crud\Attribute\Navigation\NavigationItem;
use Dakataa\Crud\Attribute\Navigation\NavigationItemInterface;
use Dakataa\Crud\Attribute\SearchableOptions;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class NavigationNormalizer implements NormalizerInterface
{

	public function __construct(protected RouterInterface $router) {

	}

	/**
	 * @param NavigationGroup|NavigationItem $object
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 */
	public function normalize(mixed $object, ?string $format = null, array $context = []): array
	{
		return [
			'title' => $object->title,
			'rank' => $object->rank,
			...($object instanceof NavigationItem ? [
				'link' => $this->router->generate($object->getControllerFQCN().'::'.$object->getControllerMethod()),
			] : [
				'items' => array_map(fn(mixed $o) => $this->normalize($o, $format, $context), $object->items)
			])
		];
	}

	public function getSupportedTypes(?string $format): array
	{
		return [
			NavigationItem::class => true,
			NavigationGroup::class => true,
		];
	}

	public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
	{
		return $data instanceof NavigationItemInterface;
	}
}

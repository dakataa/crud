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
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class NavigationNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
	use NormalizerAwareTrait;

	public function __construct(protected RouterInterface $router)
	{
	}

	/**
	 * @param NavigationGroup|NavigationItem $data
	 * @param string|null $format
	 * @param array $context
	 * @return array
	 * @throws ExceptionInterface
	 */
	public function normalize(mixed $data, ?string $format = null, array $context = []): array
	{
		return [
			'title' => $data->title,
			'rank' => $data->rank,
			...($data instanceof NavigationItem ? [
				'route' => $this->normalizer->normalize(
					$this->router->getRouteCollection()->get(
						$data->getControllerFQCN().'::'.$data->getControllerMethod()
					)
				),
			] : [
				'items' => array_map(fn(NavigationItem|NavigationGroup $o) => $this->normalize($o, $format, $context),
					$data->items ?: []),
			]),
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

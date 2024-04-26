<?php

namespace Dakataa\Crud\Service;

use Dakataa\Crud\Attribute\Navigation\NavigationGroup;
use Dakataa\Crud\Attribute\Navigation\NavigationItem;
use Dakataa\Crud\Attribute\Navigation\NavigationItemInterface;
use ReflectionClass;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

class Navigation
{
	protected array $items;

	public function __construct(
		#[TaggedLocator('dakataa.crud.navigation')]
		private ServiceLocator $handlers,
	) {
		$this->items = [];
		foreach ($this->handlers->getProvidedServices() as $serviceFQCN) {
			$reflectionClass = new ReflectionClass($serviceFQCN);
			foreach ($reflectionClass->getAttributes(NavigationGroup::class) as $reflectionAttribute) {
				/** @var NavigationGroup $navigationGroup */
				$navigationGroup = $reflectionAttribute->newInstance();
				$this->items[] = $navigationGroup;
			}

			foreach ($reflectionClass->getAttributes(NavigationItem::class) as $reflectionAttribute) {
				/** @var NavigationItem $navigationItem */
				$navigationItem = $reflectionAttribute->newInstance();
				$navigationItem->controllerFQCN ??= $serviceFQCN;
				$navigationItem->title ??= $this->humanize(
					str_replace(['Controller'], '', $reflectionClass->getShortName())
				);

				$this->items[] = $navigationItem;
			}
		}
	}

	private function humanize(string $text): string
	{
		return ucfirst(strtolower(trim(preg_replace(['/([A-Z])/', '/[_\s]+/'], ['_$1', ' '], $text))));
	}

	public function getItems(): \Generator
	{
		$getItems = function ($items, string $group = null) use (&$getItems): \Generator {
			foreach (array_filter($items, fn(NavigationItemInterface $ni) => $group === $ni->group) as $item) {
				if($item instanceof NavigationGroup) {
					$item->items = iterator_to_array($getItems($items, $item->group ?: $item->title));
				}

				yield $item;
			}
		};

		return $getItems($this->items);
	}

	public function hasNavigation(): bool {
		return !empty($this->items);
	}
}

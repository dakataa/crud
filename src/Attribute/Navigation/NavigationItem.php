<?php

namespace Dakataa\Crud\Attribute\Navigation;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class NavigationItem implements NavigationItemInterface
{
	public function __construct(
		public string $title,
		public ?string $controllerFQCN = null,
		public ?string $controllerMethod = null,
		public ?string $parentControllerFQCN = null,
		public ?string $group = null,
		public ?int $rank = null
	) {
	}

}

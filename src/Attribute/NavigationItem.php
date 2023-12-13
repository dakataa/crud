<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
#[Autoconfigure(tags: ['dakataa.crud.navigation'])]
class NavigationItem
{
	public function __construct(
		public string $title,
		public ?string $controllerFQCN = null,
		public ?string $controllerMethod = null,
		public ?string $parentControllerFQCN = null,
		public ?string $group = null
	) {
	}

}

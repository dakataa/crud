<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
#[Autoconfigure(tags: ['dakataa.crud.navigation'])]
class NavigationGroup
{
	public function __construct(
		public string $title,
		public ?string $parent = null,
		public ?int $rank = null
	) {
	}

}

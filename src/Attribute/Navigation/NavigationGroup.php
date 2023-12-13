<?php

namespace Dakataa\Crud\Attribute\Navigation;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class NavigationGroup implements NavigationItemInterface
{
	public function __construct(
		public string $title,
		public ?string $group = null,
		public ?int $rank = null,
	) {
	}

}

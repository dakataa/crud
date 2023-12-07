<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class EntityGroup
{
	public function __construct(
		public string $field
	) {
	}

}

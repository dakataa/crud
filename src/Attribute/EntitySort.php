<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Dakataa\Crud\Enum\SortTypeEnum;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class EntitySort
{
	public function __construct(
		public string $field,
		public SortTypeEnum $sort
	) {
	}

}

<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Doctrine\ORM\Query\Expr\Join;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class EntityJoinColumn
{
	public function __construct(
		public string $fqcn,
		public string $alias,
		public string $type = Join::INNER_JOIN,
		public ?string $conditionType = null,
		public ?string $condition = null
	) {
	}

}

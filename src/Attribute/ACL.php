<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ACL
{

	public function __construct(
		public ?array $permissions = null,

	) {
	}



}

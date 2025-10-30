<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class LoadAction
{

	public function __construct(
		public string $name,
	) {
	}

}

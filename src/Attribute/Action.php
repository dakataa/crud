<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Action
{
	public function __construct(
		public ?string $action = null,
		public ?string $title = null,
	) {
	}
}

<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Attribute(Attribute::TARGET_METHOD)]
class Action
{
	public function __construct(
		public ?string $action = null
	) {
	}
}

<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class EntityFinder
{
	/**
	 * Method or Class with __invoke method to handle request
	 * @param string $finder
	 */
	public function __construct(
		public string $finder
	) {
	}

}

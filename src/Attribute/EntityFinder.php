<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class EntityFinder
{
	/**
	 * Resolved in this order:
	 * - a class-string with an __invoke(Request, CrudServiceContainer) method
	 * - a method on the controller instance (called as $this->$finder(...))
	 * - a static method on the controller class (called as ControllerClass::$finder(...))
	 * - any other callable, e.g. [SomeClass::class, 'staticMethod']
	 * @param string|array $finder
	 */
	public function __construct(
		public string|array $finder
	) {
	}

}

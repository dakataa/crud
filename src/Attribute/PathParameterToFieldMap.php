<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class PathParameterToFieldMap
{
	public function __construct(
		protected string $pathParameter,
		protected string $field
	) {
	}

	public function getPathParameter(): string
	{
		return $this->pathParameter;
	}

	public function setPathParameter(string $pathParameter): PathParameterToFieldMap
	{
		$this->pathParameter = $pathParameter;

		return $this;
	}

	public function getField(): string
	{
		return $this->field;
	}

	public function setField(string $field): PathParameterToFieldMap
	{
		$this->field = $field;

		return $this;
	}


}

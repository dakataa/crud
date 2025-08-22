<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class PathParameterToFieldMap
{
	public function __construct(
		protected string $parameter,
		protected string $field
	) {
	}

	public function getParameter(): string
	{
		return $this->parameter;
	}

	public function setParameter(string $parameter): PathParameterToFieldMap
	{
		$this->parameter = $parameter;

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

<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Symfony\Component\Serializer\Attribute\Ignore;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class SearchableOptions
{
	public function __construct(
		protected ?string $field = null,
		protected ?string $type = null,
		protected ?array $options = null
	) {
	}

	public function getField(): ?string
	{
		return $this->field;
	}

	public function setField(?string $field): SearchableOptions
	{
		$this->field = $field;

		return $this;
	}

	#[Ignore]
	public function getType(): ?string
	{
		return $this->type;
	}

	public function setType(?string $type): SearchableOptions
	{
		$this->type = $type;

		return $this;
	}

	public function getOptions(): ?array
	{
		return $this->options;
	}

	public function setOptions(?array $options): SearchableOptions
	{
		$this->options = $options;

		return $this;
	}

}

<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class EntityType
{
	public function __construct(
		public string $fqcn,
		public ?array $options = null,
	) {
	}

	public function getFqcn(): string
	{
		return $this->fqcn;
	}

	public function setFqcn(string $fqcn): EntityType
	{
		$this->fqcn = $fqcn;

		return $this;
	}

	public function getOptions(): ?array
	{
		return $this->options;
	}

	public function setOptions(?array $options): EntityType
	{
		$this->options = $options;

		return $this;
	}

}

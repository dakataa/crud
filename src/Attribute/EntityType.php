<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class EntityType
{
	public function __construct(
		public string $fqcn,
		public ?array $options = null,
		public ?string $successMessage = null,
		public ?string $action = null
	) {
	}

	public function getFqcn(): string
	{
		return $this->fqcn;
	}

	public function setFqcn(string $fqcn): static
	{
		$this->fqcn = $fqcn;

		return $this;
	}

	public function getOptions(): ?array
	{
		return $this->options;
	}

	public function setOptions(?array $options): static
	{
		$this->options = $options;

		return $this;
	}

	public function getSuccessMessage(): ?string
	{
		return $this->successMessage;
	}

	public function setSuccessMessage(?string $successMessage): static
	{
		$this->successMessage = $successMessage;

		return $this;
	}

}

<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewTypeEnum;
use Dakataa\Crud\Enum\SortTypeEnum;
use Stringable;
use Symfony\Component\DependencyInjection\Container;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Column
{
	public function __construct(
		protected string $field,
		protected ?string $label = null,
		protected string|null $getter = null,
		protected ?string $placeholder = null,
		protected ?array $enum = null,
		protected bool $raw = false,
		protected ?EntityColumnViewTypeEnum $viewType = null,
		protected int|float|string|Stringable|null $value = null,
		protected SearchableOptions|bool|null $searchable = null,
		protected bool|SortTypeEnum $sortable = true,
		protected array $options = [],
		protected mixed $roles = null
	) {
	}

	public function getAlias(): string
	{
		return lcfirst(Container::camelize(str_replace('.', '_', $this->getField())));
	}

	public function getField(): string
	{
		return $this->field;
	}

	public function setField(string $field): Column
	{
		$this->field = $field;

		return $this;
	}

	public function getLabel(): ?string
	{
		$label = explode('.', $this->label ?: $this->field);

		return ucfirst(array_pop($label));
	}

	public function setLabel(?string $label): Column
	{
		$this->label = $label;

		return $this;
	}

	public function getGetter(): ?string
	{
		return $this->getter;
	}

	public function setGetter(?string $getter): Column
	{
		$this->getter = $getter;

		return $this;
	}

	public function getPlaceholder(): ?string
	{
		return $this->placeholder;
	}

	public function setPlaceholder(?string $placeholder): Column
	{
		$this->placeholder = $placeholder;

		return $this;
	}

	public function getEnum(): ?array
	{
		return $this->enum;
	}

	public function setEnum(?array $enum): Column
	{
		$this->enum = $enum;

		return $this;
	}

	public function isRaw(): bool
	{
		return $this->raw;
	}

	public function setRaw(bool $raw): Column
	{
		$this->raw = $raw;

		return $this;
	}

	public function getValue(): float|Stringable|int|string|null
	{
		return $this->value;
	}

	public function setValue(float|Stringable|int|string|null $value): Column
	{
		$this->value = $value;

		return $this;
	}

	public function getSearchable(): SearchableOptions|bool|null
	{
		return $this->searchable;
	}

	public function setSearchable(SearchableOptions|bool $searchable): Column
	{
		$this->searchable = $searchable;

		return $this;
	}

	public function getOptions(): array
	{
		return $this->options;
	}

	public function setOptions(array $options): Column
	{
		$this->options = $options;

		return $this;
	}

	public function getOption(string $key): mixed
	{
		return $this->options[$key] ?? null;
	}

	public function getRoles(): mixed
	{
		return $this->roles;
	}

	public function setRoles(mixed $roles): Column
	{
		$this->roles = $roles;

		return $this;
	}

	public function getSortable(): SortTypeEnum|bool
	{
		return $this->sortable;
	}

	public function setSortable(SortTypeEnum|bool $sortable): Column
	{
		$this->sortable = $sortable;

		return $this;
	}

	public function getViewType(): ?EntityColumnViewTypeEnum
	{
		return $this->viewType;
	}

	public function setViewType(?EntityColumnViewTypeEnum $viewType): Column
	{
		$this->viewType = $viewType;

		return $this;
	}

}

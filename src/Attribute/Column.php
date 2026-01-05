<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewGroupEnum;
use Dakataa\Crud\Enum\SortTypeEnum;
use Dakataa\Crud\Utils\StringHelper;
use Stringable;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\ExpressionLanguage\Expression;

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
		protected EntityColumnViewGroupEnum|string|null|false $group = null,
		protected int|float|string|Stringable|null $value = null,
		protected SearchableOptions|bool|null $searchable = null,
		protected bool $visible = true,
		protected bool|SortTypeEnum $sortable = true,
		protected array $options = [],
		protected string|array|null $roles = null,
		protected null|string|Expression $permission = null,
		protected bool $identifier = false
	) {
	}

	public function getAlias(): string
	{
		return lcfirst(str_replace('.', '_', Container::camelize($this->getField())));
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
		$label = $this->label ?: $this->field;

		return StringHelper::titlize($label);
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

	public function isVisible(): bool
	{
		return $this->visible;
	}

	public function setVisible(bool $visible): Column
	{
		$this->visible = $visible;

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

	public function getRoles(): string|array|null
	{
		return $this->roles;
	}

	public function setRoles(string|array $roles): Column
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

	public function getGroup(): EntityColumnViewGroupEnum|string|null|false
	{
		return (is_string($this->group) ? EntityColumnViewGroupEnum::tryFrom($this->group) : null) ?: $this->group;
	}

	public function setGroup(?EntityColumnViewGroupEnum $group): Column
	{
		$this->group = $group;

		return $this;
	}

	public function isIdentifier(): bool
	{
		return $this->identifier;
	}

	public function setIdentifier(bool $v): Column
	{
		$this->identifier = $v;

		return $this;
	}

	public function getPermission(): Expression|string|null
	{
		return $this->permission;
	}

	public function setPermission(Expression|string|null $permission): Column
	{
		$this->permission = $permission;

		return $this;
	}

}

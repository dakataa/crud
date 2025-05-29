<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Doctrine\ORM\Mapping\JoinColumn;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Entity
{
	/**
	 * @param string $fqcn
	 * @param string $alias
	 * @param array & Column[]|null $columns
	 * @param array & JoinColumn[]|null $joins
	 * @param array & string[]|null $actions
	 * @param array & EntityGroup[]|null $group
	 * @param array & EntitySort[]|null $sort
	 * @param bool $pagination
	 * @param bool $filter
	 * @param bool $batch
	 */
	public function __construct(
		public string $fqcn,
		public string $alias = 'a',
		public ?array $columns = null,
		public ?array $joins = null,
		public array|null $group = null,
		public array|null $sort = null,
		public ?array $actions = null,
		public bool $pagination = true,
		public bool $filter = true,
		public bool $batch = true
	) {
	}

	public function setFqcn(string $fqcn): static
	{
		$this->fqcn = $fqcn;

		return $this;
	}

	public function setAlias(string $alias): Entity
	{
		$this->alias = $alias;

		return $this;
	}

	public function setJoins(?array $joins): Entity
	{
		$this->joins = $joins;

		return $this;
	}

	public function setGroup(?array $group): Entity
	{
		$this->group = $group;

		return $this;
	}

	public function setColumns(?array $columns): Entity
	{
		$this->columns = $columns;

		return $this;
	}

	public function getFqcn(): string
	{
		return $this->fqcn;
	}

	public function getAlias(): string
	{
		return $this->alias;
	}

	public function getColumns(): ?array
	{
		return $this->columns;
	}

	public function getJoins(): ?array
	{
		return $this->joins;
	}

	public function getGroup(): ?array
	{
		return $this->group;
	}

	public function getSort(): ?array
	{
		return $this->sort;
	}

	public function setSort(?array $sort): void
	{
		$this->sort = $sort;
	}

	public function isPagination(): bool
	{
		return $this->pagination;
	}

	public function setPagination(bool $pagination): Entity
	{
		$this->pagination = $pagination;

		return $this;
	}

	public function isBatch(): bool
	{
		return $this->batch;
	}

	public function setBatch(bool $batch): Entity
	{
		$this->batch = $batch;

		return $this;
	}

	public function getActions(): ?array
	{
		return $this->actions;
	}

	public function setActions(?array $actions): Entity
	{
		$this->actions = $actions;

		return $this;
	}

	public function isFilter(): bool
	{
		return $this->filter;
	}

	public function setFilter(bool $filter): Entity
	{
		$this->filter = $filter;

		return $this;
	}

}

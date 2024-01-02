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
	 * @param array & EntityGroup[]|null $group
	 */
	public function __construct(
		public string $fqcn,
		public string $alias = 'a',
		public ?array $columns = null,
		public ?array $joins = null,
		public array|null $group = null
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

}

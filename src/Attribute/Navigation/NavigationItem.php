<?php

namespace Dakataa\Crud\Attribute\Navigation;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class NavigationItem implements NavigationItemInterface
{
	public function __construct(
		public string $title,
		public ?string $controllerFQCN = null,
		public ?string $controllerMethod = null,
		public ?string $parentControllerFQCN = null,
		public ?string $group = null,
		public ?int $rank = null
	) {
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): NavigationItem
	{
		$this->title = $title;

		return $this;
	}

	public function getControllerFQCN(): ?string
	{
		return $this->controllerFQCN;
	}

	public function setControllerFQCN(?string $controllerFQCN): NavigationItem
	{
		$this->controllerFQCN = $controllerFQCN;

		return $this;
	}

	public function getControllerMethod(): ?string
	{
		return $this->controllerMethod;
	}

	public function setControllerMethod(?string $controllerMethod): NavigationItem
	{
		$this->controllerMethod = $controllerMethod;

		return $this;
	}

	public function getParentControllerFQCN(): ?string
	{
		return $this->parentControllerFQCN;
	}

	public function setParentControllerFQCN(?string $parentControllerFQCN): NavigationItem
	{
		$this->parentControllerFQCN = $parentControllerFQCN;

		return $this;
	}

	public function getGroup(): ?string
	{
		return $this->group;
	}

	public function setGroup(?string $group): NavigationItem
	{
		$this->group = $group;

		return $this;
	}

	public function getRank(): ?int
	{
		return $this->rank;
	}

	public function setRank(?int $rank): NavigationItem
	{
		$this->rank = $rank;

		return $this;
	}
}

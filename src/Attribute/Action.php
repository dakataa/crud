<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Dakataa\Crud\Attribute\Enum\ActionVisibilityEnum;
use Symfony\Component\Routing\Attribute\Route;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Action
{
	private ?string $method = null;

	public function __construct(
		public ?string $name = null,
		public ?string $title = null,
		public ?Route $route = null,
		public ?string $namespace = null,
		public ?string $entity = null,
		public ?array $options = null,
		public ?ActionVisibilityEnum $visibility = ActionVisibilityEnum::List
	) {
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): Action
	{
		$this->name = $name;

		return $this;
	}

	public function getTitle(): ?string
	{
		return $this->title;
	}

	public function setTitle(?string $title): Action
	{
		$this->title = $title;

		return $this;
	}

	public function getRoute(): ?Route
	{
		return $this->route;
	}

	public function setRoute(?Route $route): Action
	{
		$this->route = $route;

		return $this;
	}

	public function getNamespace(): ?string
	{
		return $this->namespace;
	}

	public function setNamespace(?string $namespace): Action
	{
		$this->namespace = $namespace;

		return $this;
	}

	public function getEntity(): ?string
	{
		return $this->entity;
	}
	public function setEntity(?string $entity): Action
	{
		$this->entity = $entity;

		return $this;
	}

	public function getMethod(): ?string
	{
		return $this->method;
	}

	public function setMethod(?string $method): Action
	{
		$this->method = $method;

		return $this;
	}

	public function getVisibility(): ?ActionVisibilityEnum
	{
		return $this->visibility;
	}

	public function setVisibility(?ActionVisibilityEnum $visibility): Action
	{
		$this->visibility = $visibility;

		return $this;
	}

}

<?php

namespace Dakataa\Crud\Attribute;

use Attribute;
use Symfony\Component\Routing\Attribute\Route;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Action
{
	public function __construct(
		public ?string $name = null,
		public ?string $title = null,
		public ?Route $route = null,
		public ?bool $object = false,
		public ?string $namespace = null,
		public ?string $entity = null
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

	public function getObject(): ?bool
	{
		return $this->object;
	}

	public function setObject(?bool $object): Action
	{
		$this->object = $object;

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

}

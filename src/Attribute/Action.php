<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Action
{
	public function __construct(
		public ?string $action = null,
		public ?string $title = null,
		public ?string $route = null,
		public ?bool $object = false
	) {
	}

	public function getAction(): ?string
	{
		return $this->action;
	}

	public function setAction(?string $action): Action
	{
		$this->action = $action;

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

	public function getRoute(): ?string
	{
		return $this->route;
	}

	public function setRoute(?string $route): Action
	{
		$this->route = $route;

		return $this;
	}

}

<?php

namespace Dakataa\Crud\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Action
{
	public function __construct(
		public ?string $action = null,
		public ?string $title = null,
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

}

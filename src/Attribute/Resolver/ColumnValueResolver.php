<?php

namespace Dakataa\Crud\Attribute\Resolver;

use Attribute;
use Dakataa\Crud\Attribute\Column;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ColumnValueResolver extends AbstractResolver implements ColumnResolverInterface
{
	/**
	 * @param string|array $resolver
	 * @param array<string>|null $fields
	 */
	public function __construct(
		string|array $resolver,
		public ?array $fields = null
	) {
		parent::__construct($resolver);
	}

	public function supports(Column $column): bool
	{
		return !$this->fields || in_array($column->getField(), $this->fields, true);
	}
}

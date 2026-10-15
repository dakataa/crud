<?php

namespace Dakataa\Crud\Attribute\Resolver;

use Attribute;
use Dakataa\Crud\Attribute\Column;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class FormTypeSearchOptionsResolver extends AbstractResolver implements ColumnResolverInterface
{
	/**
	 * @param array<string>|null $fields Column field names, not form aliases.
	 */
	public function __construct(string|array $resolver, public ?array $fields = null)
	{
		parent::__construct($resolver);
	}

	public function supports(Column $column): bool
	{
		return !$this->fields || in_array($column->getField(), $this->fields, true);
	}
}

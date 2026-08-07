<?php

namespace Dakataa\Crud\Attribute\Resolver;

use Dakataa\Crud\Attribute\Column;

interface ColumnResolverInterface extends ResolverInterface
{
	public function supports(Column $column): bool;
}

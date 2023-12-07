<?php

namespace Dakataa\Crud\Controller;

use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\EntityType;

interface CrudControllerInterface
{
	const COLUMNS_LIST = 'list';
	const COLUMNS_VIEW = 'view';

	public function getEntity(): Entity;

	public function getEntityType(): ?EntityType;
}

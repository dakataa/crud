<?php

namespace Dakataa\Crud\Controller;

use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\EntityType;

interface CrudControllerInterface
{

	public function getEntity(bool $required = false): Entity|null;

	public function getEntityType(): EntityType|null;

	public function setServiceContainer(CrudServiceContainer $loader): void;
}

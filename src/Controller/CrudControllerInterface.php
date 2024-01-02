<?php

namespace Dakataa\Crud\Controller;

use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\EntityType;

interface CrudControllerInterface
{

	public function getEntity(): Entity;

	public function getEntityType(): ?EntityType;
}

<?php

namespace Dakataa\Crud\Controller;

use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\EntityType;
use Symfony\Component\HttpFoundation\Request;

interface CrudControllerInterface
{

	public function getEntity(Request $request): Entity|null;

	public function getEntityType(Request $request): ?EntityType;

	public function setServiceContainer(CrudServiceContainer $loader): void;
}

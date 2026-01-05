<?php

namespace Dakataa\Crud\Security;

use Dakataa\Crud\Attribute\Entity;

class SecuritySubject
{
	public function __construct(public Entity $entity, public object|null $object = null)
	{

	}
}

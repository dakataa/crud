<?php

namespace Dakataa\Crud\Service;

use Symfony\Component\HttpFoundation\Request;

class CrudContext
{

	public string $method;

	public string $controller;

	public function __construct(public Request $request)
	{
		[$this->controller, $this->method] = explode('::', $request->attributes->get('_controller'));
	}

}

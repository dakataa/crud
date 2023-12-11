<?php

namespace Dakataa\Crud;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class DakataaCrudBundle extends Bundle
{
	const NAME = 'dakataa_crud';
	
	public function build(ContainerBuilder $container): void
	{
	}
}

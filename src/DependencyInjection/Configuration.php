<?php

namespace Dakataa\Crud\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
	public function getConfigTreeBuilder(): TreeBuilder
	{
		$treeBuilder = new TreeBuilder('crud');
		return $treeBuilder;
	}
}

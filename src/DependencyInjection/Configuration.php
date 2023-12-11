<?php

namespace Dakataa\Crud\DependencyInjection;

use Dakataa\Crud\DakataaCrudBundle;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
	public function getConfigTreeBuilder(): TreeBuilder
	{
		$treeBuilder = new TreeBuilder(DakataaCrudBundle::NAME);
		$treeBuilder
			->getRootNode()
			->children()
			->variableNode('layout')->isRequired()
			->end()
			->end();

		return $treeBuilder;
	}
}

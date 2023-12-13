<?php

namespace Dakataa\Crud\Twig\Extension;

use Dakataa\Crud\Service\Navigation;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class NavigationExtension extends AbstractExtension
{

	public function __construct(
		protected Navigation $navigation
	) {
	}

	public function getFunctions(): array
	{
		return [
			new TwigFunction('getNavigation', [$this, 'getNavigation']),
		];
	}

	public function getNavigation(): Navigation
	{
		return $this->navigation;
	}

}

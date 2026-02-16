<?php

namespace Dakataa\Crud\Serializer;

use Dakataa\Crud\Serializer\Normalizer\ActionNormalizer;
use Dakataa\Crud\Serializer\Normalizer\ColumnNormalizer;
use Dakataa\Crud\Serializer\Normalizer\FormErrorNormalizer;
use Dakataa\Crud\Serializer\Normalizer\FormViewNormalizer;
use Dakataa\Crud\Serializer\Normalizer\RouteNormalizer;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;

class CrudSerializer extends Serializer
{

	public function __construct(RouterInterface $router)
	{
		parent::__construct([
			new FormErrorNormalizer,
			new FormViewNormalizer,
			new BackedEnumNormalizer,
			new ColumnNormalizer,
			new ActionNormalizer($router),
			new DateTimeNormalizer([
				DateTimeNormalizer::FORMAT_KEY => 'Y-m-d H:i:s',
			]),
			new RouteNormalizer,
		], defaultContext: [
			AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => fn() => null,
		]);
	}
}

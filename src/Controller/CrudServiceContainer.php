<?php

namespace Dakataa\Crud\Controller;

use Dakataa\Crud\Serializer\CrudSerializer;
use Dakataa\Crud\Service\ActionCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class CrudServiceContainer
{
	public function __construct(
		public FormFactoryInterface $formFactory,
		public RouterInterface $router,
		public EventDispatcherInterface $dispatcher,
		public EntityManagerInterface $entityManager,
		public ParameterBagInterface $parameterBag,
		public ActionCollection $actionCollection,
		public CrudSerializer $serializer,
		public ?TokenStorageInterface $tokenStorage = null,
		public ?AuthorizationCheckerInterface $authorizationChecker = null,
		public ?TemplateProvider $templateProvider = null
	) {
	}
}

<?php

namespace Dakataa\Crud\EventSubscriber;

use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Controller\AbstractCrudController;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionAttribute;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

class CrudSubscriber
{
	public function __construct(
		protected FormFactoryInterface $formFactory,
		protected RouterInterface $router,
		protected EventDispatcherInterface $dispatcher,
		protected EntityManagerInterface $entityManager,
		protected ParameterBagInterface $parameterBag,
		protected AuthorizationCheckerInterface $authorizationChecker,
		protected ?Environment $twig = null
	) {
	}

	private ?AbstractCrudController $controller = null;

	#[AsEventListener]
	public function onKernelController(ControllerEvent $event): void
	{
		if (empty($actions = $event->getAttributes(Action::class))) {
			return;
		}

		[$controllerObject, $method] = $event->getController();

		if (is_a($controllerObject, AbstractCrudController::class, true)) {
			$this->controller = $controllerObject;

			return;
		}

		/** @var Action $action */
		$action = array_shift($actions);
		$this->controller = new class (
			$this,
			$event,
			$this->formFactory,
			$this->router,
			$this->dispatcher,
			$this->entityManager,
			$this->parameterBag,
			$this->twig,
			$this->authorizationChecker
		) extends AbstractCrudController {

			protected string $originClassName;

			public function __construct(
				protected CrudSubscriber $crudSubscriber,
				protected ControllerEvent $controllerEvent,
				FormFactoryInterface $formFactory,
				RouterInterface $router,
				EventDispatcherInterface $dispatcher,
				EntityManagerInterface $entityManager,
				ParameterBagInterface $parameterBag,
				?Environment $twig = null,
				?AuthorizationCheckerInterface $authorizationChecker = null
			) {
				$this->originClassName = get_class($this->controllerEvent->getController()[0]);

				parent::__construct(
					$formFactory,
					$router,
					$dispatcher,
					$entityManager,
					$parameterBag,
					$twig,
					authorizationChecker: $authorizationChecker
				);
			}

			protected function getControllerClass(): string
			{
				return $this->originClassName;
			}

			protected function getPHPAttributes(string $attributeFQCN, string $method = null): array
			{
				if($method)
					$this->crudSubscriber->getPHPAttributes($this->controllerEvent, $attributeFQCN);

				return $this->crudSubscriber->getPHPAttributes($this->controllerEvent, $attributeFQCN);
			}
		};

		/** @var IsGranted $attribute */
		foreach ($event->getAttributes(IsGranted::class) as $attribute) {
			if (!$this->authorizationChecker->isGranted($attribute->attribute)) {
				$message = $attribute->message ?: sprintf(
					'Access Denied by #[IsGranted(%s)] on controller',
					$attribute->attribute
				);

				if ($statusCode = $attribute->statusCode) {
					throw new HttpException($statusCode, $message, code: $attribute->exceptionCode ?? 0);
				}

				$accessDeniedException = new AccessDeniedException($message, code: $attribute->exceptionCode ?? 403);
				$accessDeniedException->setAttributes($attribute->attribute);

				throw $accessDeniedException;
			}
		}

		if (!method_exists($this->controller, $action->name)) {
			return;
		}

		$event->setController([$this->controller, $action->name], [Action::class => [$action]]);
	}

	public function getPHPAttributes(ControllerEvent $controllerEvent, string $attributeClass): array
	{
		$attributes = array_reverse($controllerEvent->getAttributes($attributeClass));
		if (!empty($attributes)) {
			return $attributes;
		}

		// Get Method Attributes
		return array_map(
			fn(ReflectionAttribute $reflectionAttribute) => $reflectionAttribute->newInstance(),
			$controllerEvent->getControllerReflector()->getAttributes($attributeClass)
		);
	}

	public function getController(): ?AbstractCrudController
	{
		return $this->controller;
	}

}

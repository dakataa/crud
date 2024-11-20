<?php

namespace Dakataa\Crud\EventSubscriber;

use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Controller\AbstractCrudController;
use Dakataa\Crud\Service\ActionCollection;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionAttribute;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Dakataa\Crud\Twig\TemplateProvider;

class CrudSubscriber
{
	public function __construct(
		protected FormFactoryInterface $formFactory,
		protected RouterInterface $router,
		protected EventDispatcherInterface $dispatcher,
		protected EntityManagerInterface $entityManager,
		protected ParameterBagInterface $parameterBag,
		protected ActionCollection $actionCollection,
		protected ?AuthorizationCheckerInterface $authorizationChecker = null,
		protected ?TemplateProvider $templateProvider = null,
	) {
	}

	private ?AbstractCrudController $controller = null;

	#[AsEventListener]
	public function onKernelController(ControllerArgumentsEvent $event): void
	{
		if(!is_array($event->getController())) {
			return;
		}

		[$controllerObject] = $event->getController();
		if (is_a($controllerObject, AbstractCrudController::class, true)) {
			$this->controller = $controllerObject;
		}

		[$controllerClass, $method] = explode('::', $event->getRequest()->get('_controller'));

		if(null === $action = $this->actionCollection->load($controllerClass, method: $method)->current()) {
			return;
		}

		$this->controller = new class (
			$this,
			$event,
			$this->formFactory,
			$this->router,
			$this->dispatcher,
			$this->entityManager,
			$this->parameterBag,
			$this->actionCollection,
			$this->authorizationChecker,
			$this->templateProvider
		) extends AbstractCrudController {

			protected string $originClassName;

			public function __construct(
				protected CrudSubscriber $crudSubscriber,
				protected ControllerArgumentsEvent $controllerEvent,
				FormFactoryInterface $formFactory,
				RouterInterface $router,
				EventDispatcherInterface $dispatcher,
				EntityManagerInterface $entityManager,
				ParameterBagInterface $parameterBag,
				ActionCollection $actionCollection,
				?AuthorizationCheckerInterface $authorizationChecker = null,
				?TemplateProvider $templateProvider = null,
			) {
				$this->originClassName = get_class($this->controllerEvent->getController()[0]);

				parent::__construct(
					$formFactory,
					$router,
					$dispatcher,
					$entityManager,
					$parameterBag,
					$actionCollection,
					authorizationChecker: $authorizationChecker,
					templateProvider: $templateProvider
				);
			}

			public function getControllerClass(): string
			{
				return $this->originClassName;
			}

			protected function getPHPAttributes(string $attributeFQCN, string $method = null): array
			{
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

		$event->setController([$this->controller, $action->name], $event->getAttributes());
	}

	public function getPHPAttributes(ControllerArgumentsEvent $controllerEvent, string $attributeClass): array
	{
		$attributes = array_reverse($controllerEvent->getAttributes($attributeClass));
		if (!empty($attributes)) {
			return $attributes;
		}

		// Get Method Attributes
		return array_map(
			fn(ReflectionAttribute $reflectionAttribute) => $reflectionAttribute->newInstance(),
			$controllerEvent->getAttributes($attributeClass)
		);
	}

	public function getController(): ?AbstractCrudController
	{
		return $this->controller;
	}

}

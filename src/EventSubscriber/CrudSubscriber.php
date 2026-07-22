<?php

namespace Dakataa\Crud\EventSubscriber;

use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\LoadAction;
use Dakataa\Crud\Controller\AbstractCrudController;
use Dakataa\Crud\Controller\CrudServiceContainer;
use Dakataa\Crud\Service\ActionCollection;
use Dakataa\Crud\Service\CrudContext;
use Dakataa\Crud\Twig\TemplateProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CrudSubscriber
{
	public function __construct(
		protected CrudServiceContainer $crudServiceContainer,
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
		if (!is_array($event->getController())) {
			return;
		}

		[$controllerObject, $method] = $event->getController();
		[$controllerClass] = explode('::', $event->getRequest()->attributes->get('_controller'));

		if (!class_exists($controllerClass)) {
			return;
		}

		if (null === $this->actionCollection->load($controllerClass, method: $method)->current()) {
			return;
		}

		/** @var LoadAction|bool $loadAction */
		$loadAction = current($event->getAttributes(LoadAction::class)) ?: new LoadAction($method);

		if (false === is_a($controllerObject, AbstractCrudController::class, true)) {
			$this->controller = new class (
				$event->getController()[0],
				$this,
				$event
			) extends AbstractCrudController {

				public function __construct(
					protected object $originalController,
					protected CrudSubscriber $crudSubscriber,
					protected ControllerArgumentsEvent $controllerEvent
				) {
				}

				public function getControllerClass(): string
				{
					return get_class($this->originalController);
				}

				public function getResolverContext(): object
				{
					return $this->originalController;
				}

				public function buildFormTypeOptions(Request $request, Action $action, array $options): array
				{
					if (method_exists($this->originalController, 'buildFormTypeOptions')) {
						return $this->originalController->buildFormTypeOptions($request, $action, $options);
					}

					return parent::buildFormTypeOptions($request, $action, $options);
				}

				public function buildCustomQuery(Request $request, Action $action, QueryBuilder $query): void
				{
					if (method_exists($this->originalController, 'buildCustomQuery')) {
						$this->originalController->buildCustomQuery($request, $action, $query);

						return;
					}

					parent::buildCustomQuery($request, $action, $query);
				}
			};

			$this->controller->setServiceContainer($this->crudServiceContainer);
		} else {
			$this->controller = $controllerObject;
		}

		if (!method_exists($this->controller, $loadAction->name)) {
			return;
		}

		$this->controller->setContext(new CrudContext($event->getRequest()));

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

		$event->setController([$this->controller, $loadAction->name], $event->getAttributes());
	}


	public function getController(): ?AbstractCrudController
	{
		return $this->controller;
	}

}

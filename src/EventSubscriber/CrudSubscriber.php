<?php

namespace Dakataa\Crud\EventSubscriber;


use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Controller\AbstractCrudController;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
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
		protected Environment $twig
	) {
	}

	#[AsEventListener]
	public function onKernelController(ControllerEvent $event): void
	{
		if (empty($actions = $event->getAttributes(Action::class))) {
			return;
		}

		[$controllerObject] = $event->getController();

		if (is_a($controllerObject, AbstractCrudController::class, true)) {
			return;
		}

		/** @var Action $action */
		$action = array_shift($actions);
		$parent = $this;
		$controller = new class (
			$this,
			$event,
			$this->formFactory,
			$this->router,
			$this->dispatcher,
			$this->entityManager,
			$this->parameterBag,
			$this->twig
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
				Environment $twig
			) {
				$this->originClassName = get_class($this->controllerEvent->getController()[0]);

				parent::__construct($formFactory, $router, $dispatcher, $entityManager, $parameterBag, $twig);
			}

			protected function getControllerClass(): string
			{
				return $this->originClassName;
			}

			protected function getAttributes(string $attributeClass): array
			{
				// Get Method Attributes
				$attributes = array_map(
					fn(ReflectionAttribute $reflectionAttribute) => $reflectionAttribute->newInstance(),
					$this->controllerEvent->getControllerReflector()->getAttributes($attributeClass)
				);

				if (!empty($attributes)) {
					return $attributes;
				}

				// Return Class Attributes if Method Attributes not exists
				return array_reverse($this->controllerEvent->getAttributes($attributeClass));
			}

			public function getMappedRoutes(): array
			{
				return $this->crudSubscriber->getMapActionToRoute($this->getControllerClass());
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

		$event->setController([$controller, $action->action]);
	}

	public function getMapActionToRoute(string $class): array
	{
		$reflectionClass = new ReflectionClass($class);

		return array_reduce(
			$reflectionClass->getMethods(),
			function (array $result, ReflectionMethod $reflectionMethod) use ($reflectionClass) {
				$actionAttributes = $reflectionMethod->getAttributes(Action::class);
				if (empty($actionAttributes)) {
					return $result;
				}

				return array_merge(
					$result,
					...
					array_filter(
						array_map(
							function (ReflectionAttribute $reflectionAttribute) use (
								$reflectionClass,
								$reflectionMethod
							) {
								/** @var Route|null $routeAttribute */
								$routeAttribute = ($reflectionMethod->getAttributes(
									Route::class
								)[0] ?? null)?->newInstance();

								/** @var IsGranted[] $isGrantedAttributes */
								$isGrantedAttributes = array_map(
									fn(ReflectionAttribute $refAttribute) => $refAttribute->newInstance(),
									$reflectionMethod->getAttributes(IsGranted::class)
								);

								foreach ($isGrantedAttributes as $isGrantedAttribute) {
									if (!$this->authorizationChecker->isGranted($isGrantedAttribute->attribute)) {
										return null;
									}
								}

								return [
									($reflectionAttribute->newInstance(
									)->action ?: $reflectionMethod->name) => $routeAttribute?->getName(
									) ?: ($reflectionClass->name.'::'.$reflectionMethod->name),
								];
							},
							$actionAttributes
						)
					)
				);
			},
			[]
		);
	}
}

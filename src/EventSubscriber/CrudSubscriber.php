<?php

namespace Dakataa\Crud\EventSubscriber;

use Dakataa\Crud\Controller\AbstractCrudController;
use Dakataa\Crud\Controller\CrudServiceContainer;
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

        [$controllerClass] = explode('::', $event->getRequest()->get('_controller'));

        if (!class_exists($controllerClass)) {
            return;
        }

        if (null === $action = $this->actionCollection->load($controllerClass, method: $method)->current()) {
            return;
        }

        if ($action->getMethod() === $method && is_a($controllerObject, AbstractCrudController::class, true)) {
            $this->controller = $controllerObject;

            return;
        }

        $this->controller = new class (
            get_class($event->getController()[0]),
            $this,
            $event
        ) extends AbstractCrudController {

            public function __construct(
                protected string $originClassName,
                protected CrudSubscriber $crudSubscriber,
                protected ControllerArgumentsEvent $controllerEvent
            ) {
            }

            public function getControllerClass(): string
            {
                return $this->originClassName;
            }

            protected function getPHPAttributes(string $attributeFQCN, string|null $method = null): array
            {
                return $this->crudSubscriber->getPHPAttributes($this->controllerEvent, $attributeFQCN);
            }
        };

        $this->controller->setServiceContainer($this->crudServiceContainer);

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

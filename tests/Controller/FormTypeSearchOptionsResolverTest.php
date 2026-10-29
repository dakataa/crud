<?php

namespace Dakataa\Crud\Tests\Controller;

use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\Resolver\FormTypeSearchOptionsResolver;
use Dakataa\Crud\Attribute\SearchableOptions;
use Dakataa\Crud\Controller\AbstractCrudController;
use Dakataa\Crud\Controller\CrudServiceContainer;
use Dakataa\Crud\Service\CrudContext;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use UnexpectedValueException;

class FormTypeSearchOptionsResolverTest extends TestCase
{
	public function testFieldOptionsAreResolvedInOrderAfterDefaultsAndSearchableOptions(): void
	{
		$request = $this->request(['help' => 'Request help']);
		$controller = $this->controller($request);
		$form = $controller->filterForm($request);
		$options = $form->get('name')->getConfig()->getOptions();

		self::assertSame(['class', 'method'], $controller->calls);
		self::assertFalse($options['required']);
		self::assertSame('Search Name', $options['label']);
		self::assertSame('Request help', $options['help']);
		self::assertSame(['data-static' => 'yes', 'data-class' => 'yes', 'data-method' => 'yes'], $options['attr']);
		self::assertSame('All', $form->get('active')->getConfig()->getOption('placeholder'));
	}

	public function testReturningNullOmitsOnlyTheFieldAndStopsItsResolverChain(): void
	{
		$request = $this->request(['hide' => '1']);
		$controller = $this->controller($request);
		$form = $controller->filterForm($request);

		self::assertFalse($form->has('name'));
		self::assertTrue($form->has('active'));
		self::assertSame(['class'], $controller->calls);
		self::assertCount(2, iterator_to_array($controller->getEntityColumns()));
	}

	public function testInvalidReturnTypeIsReported(): void
	{
		$request = $this->request(['invalid' => '1']);
		$controller = $this->controller($request);
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Form type search options resolver must return an array or null.');
		$controller->filterForm($request);
	}

	public function testOptionsAreResolvedAgainForEachRequest(): void
	{
		$first = $this->request(['help' => 'First']);
		$controller = $this->controller($first);
		self::assertSame('First', $controller->filterForm($first)->get('name')->getConfig()->getOption('help'));
		$second = $this->request(['help' => 'Second']);
		$controller->setContext(new CrudContext($second));
		self::assertSame('Second', $controller->filterForm($second)->get('name')->getConfig()->getOption('help'));
	}

	public function testResolvedFieldStillHandlesSubmittedFilters(): void
	{
		$request = $this->request(['filter' => ['name' => 'Ivan']]);
		$form = $this->controller($request)->filterForm($request);
		self::assertTrue($form->isSubmitted());
		self::assertTrue($form->isValid());
		self::assertSame('Ivan', $form->get('name')->getData());
	}

	private function request(array $query): Request
	{
		return new Request($query, attributes: ['_controller' => SearchOptionsController::class.'::search']);
	}

	private function controller(Request $request): SearchOptionsController
	{
		$metadata = new ClassMetadata(SearchOptionsEntity::class);
		$metadata->mapField(['fieldName' => 'name', 'type' => Types::STRING]);
		$metadata->mapField(['fieldName' => 'active', 'type' => Types::BOOLEAN]);
		$metadata->initializeReflection(new RuntimeReflectionService());
		$metadata->wakeupReflection(new RuntimeReflectionService());
		$manager = $this->createStub(EntityManagerInterface::class);
		$manager->method('getClassMetadata')->willReturn($metadata);
		$parameters = $this->createStub(ParameterBagInterface::class);
		$parameters->method('get')->willReturn(false);
		$services = (new ReflectionClass(CrudServiceContainer::class))->newInstanceWithoutConstructor();
		$services->entityManager = $manager;
		$services->parameterBag = $parameters;
		$services->formFactory = Forms::createFormFactoryBuilder()->addExtension(new HttpFoundationExtension())->getFormFactory();
		$controller = new SearchOptionsController();
		$controller->setServiceContainer($services);
		$controller->setContext(new CrudContext($request));
		return $controller;
	}
}

class SearchOptionsEntity
{
	public ?string $name = null;
	public ?bool $active = null;
}

#[Entity(SearchOptionsEntity::class, filter: true)]
#[Column('name', searchable: new SearchableOptions(type: TextType::class, options: ['label' => 'Search Name', 'attr' => ['data-static' => 'yes']]))]
#[Column('active')]
#[FormTypeSearchOptionsResolver('classOptions', fields: ['name'])]
#[FormTypeSearchOptionsResolver('unmatchedOptions', fields: ['other'])]
class SearchOptionsController extends AbstractCrudController
{
	public array $calls = [];

	public function getAction(Request $request, ?string $name = null): ?Action
	{
		return (new Action('list'))->setMethod('search');
	}

	#[FormTypeSearchOptionsResolver('methodOptions', fields: ['name'])]
	public function search(): void
	{
	}

	public function filterForm(Request $request): ?FormInterface
	{
		return $this->getFilterForm($request);
	}

	public function classOptions(Request $request, ?Action $action, Column $column, array $options, CrudServiceContainer $services): mixed
	{
		$this->calls[] = 'class';
		if ($request->query->getBoolean('hide')) {
			return null;
		}
		if ($request->query->getBoolean('invalid')) {
			return 'invalid';
		}
		return [...$options, 'help' => $request->query->get('help'), 'attr' => [...$options['attr'], 'data-class' => 'yes']];
	}

	public function methodOptions(Request $request, ?Action $action, Column $column, array $options, CrudServiceContainer $services): array
	{
		$this->calls[] = 'method';
		return [...$options, 'attr' => [...$options['attr'], 'data-method' => 'yes']];
	}

	public function unmatchedOptions(): array
	{
		throw new \LogicException('Resolver for another field was called.');
	}
}

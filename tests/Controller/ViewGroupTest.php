<?php

namespace Dakataa\Crud\Tests\Controller;

use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\SearchableOptions;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewGroupEnum;
use Dakataa\Crud\Controller\AbstractCrudController;
use Dakataa\Crud\Controller\CrudServiceContainer;
use Dakataa\Crud\Service\CrudContext;
use Dakataa\Crud\Serializer\CrudSerializer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ViewGroupTest extends TestCase
{
	public function testDefaultListRetainsGroupedFilters(): void
	{
		$request = new Request(['filter' => ['name' => 'Ivan']]);
		$controller = $this->controller($request);
		$form = $controller->filterForm($request);
		self::assertTrue($form->isValid());
		self::assertSame('Ivan', $form->get('name')->getData());
		self::assertTrue($form->has('id'));
		self::assertFalse($form->has('detail'));
		self::assertFalse($form->has('compact'));
	}

	public function testCustomGroupKeepsItsFilterQueryAndSortTogether(): void
	{
		$request = new Request(['viewGroup' => 'compact', 'filter' => ['compact' => 'match'], 'sort' => ['compact' => 'DESC']]);
		$controller = $this->controller($request);
		$form = $controller->filterForm($request);
		self::assertTrue($form->isValid());
		self::assertFalse($form->has('name'));
		self::assertFalse($form->has('detail'));
		self::assertTrue($form->has('id'));
		$query = $controller->query($request, 'compact');
		self::assertStringContainsString('a.compact LIKE :pcompact', $query->getDQL());
		self::assertStringContainsString('a.compact DESC', $query->getDQL());
		self::assertSame('%match%', $query->getParameter('pcompact')->getValue());
		self::assertStringNotContainsString('a.name', $query->getDQL());
	}

	public function testExplicitGroupOverridesQueryForInternalCallers(): void
	{
		$request = new Request(['viewGroup' => 'compact']);
		$form = $this->controller($request)->filterForm($request, EntityColumnViewGroupEnum::View);
		self::assertTrue($form->has('name'));
		self::assertTrue($form->has('detail'));
		self::assertFalse($form->has('compact'));
	}

	public function testUnexposedFilterIsAppliedButNotIncludedInSerializedView(): void
	{
		foreach ([EntityColumnViewGroupEnum::List, 'compact'] as $group) {
			foreach ([0, 42] as $id) {
				$request = new Request(['filter' => ['id' => (string) $id]]);
				$controller = $this->controller($request);
				$form = $controller->filterForm($request, $group);
				self::assertTrue($form->isValid());
				self::assertSame($id, $form->get('id')->getData());
				$serializer = new CrudSerializer($this->createStub(RouterInterface::class));
				$payload = $serializer->normalize($form->createView());
				foreach (['children', 'data', 'value'] as $key) {
					self::assertArrayNotHasKey('id', $payload[$key]);
				}
				self::assertArrayHasKey($group === 'compact' ? 'compact' : 'name', $payload['children']);
				self::assertSame($id, $form->getData()['id']);
				$query = $controller->query($request, $group);
				self::assertStringContainsString('a.id IN (:pid)', $query->getDQL());
				self::assertSame($id, $query->getParameter('pid')->getValue());
			}
		}
	}

	public function testUnexposedFilterStillValidatesInput(): void
	{
		$request = new Request(['filter' => ['id' => 'not-an-integer']]);
		$form = $this->controller($request)->filterForm($request);
		self::assertTrue($form->isSubmitted());
		self::assertFalse($form->isValid());
	}

	public function testUnsubmittedViewOmitsUnexposedFieldAndKeepsDefaultExposedFields(): void
	{
		$request = new Request();
		$controller = $this->controller($request);
		$form = $controller->filterForm($request);
		$view = $form->createView();
		self::assertTrue((new SearchableOptions())->isExposed());
		self::assertFalse($form->isSubmitted());
		self::assertTrue($form->has('id'));
		self::assertArrayNotHasKey('id', $view->children);
		self::assertArrayHasKey('name', $view->children);
	}

	private function controller(Request $request): GroupedController
	{
		$request->attributes->set('_controller', GroupedController::class.'::list');
		$request->setSession(new Session(new MockArraySessionStorage()));
		$metadata = new ClassMetadata(GroupedEntity::class);
		$metadata->mapField(['fieldName' => 'id', 'type' => Types::INTEGER, 'id' => true]);
		foreach (['name', 'detail', 'compact'] as $field) {
			$metadata->mapField(['fieldName' => $field, 'type' => Types::STRING]);
		}
		$metadata->initializeReflection(new RuntimeReflectionService());
		$metadata->wakeupReflection(new RuntimeReflectionService());
		$manager = $this->createStub(EntityManagerInterface::class);
		$manager->method('getClassMetadata')->willReturn($metadata);
		$repository = $this->createStub(EntityRepository::class);
		$repository->method('createQueryBuilder')->willReturn((new QueryBuilder($manager))->select('a')->from(GroupedEntity::class, 'a'));
		$manager->method('getRepository')->willReturn($repository);
		$parameters = $this->createStub(ParameterBagInterface::class);
		$parameters->method('get')->willReturn(false);
		$services = (new ReflectionClass(CrudServiceContainer::class))->newInstanceWithoutConstructor();
		$services->entityManager = $manager;
		$services->parameterBag = $parameters;
		$services->formFactory = Forms::createFormFactoryBuilder()->addExtension(new HttpFoundationExtension())->getFormFactory();
		$controller = new GroupedController();
		$controller->setServiceContainer($services);
		$controller->setContext(new CrudContext($request));
		return $controller;
	}
}

class GroupedEntity
{
	public ?int $id = null;
	public ?string $name = null;
	public ?string $detail = null;
	public ?string $compact = null;
}

#[Entity(GroupedEntity::class)]
#[Column('id', searchable: new SearchableOptions(type: IntegerType::class, exposed: false))]
#[Column('name', group: ['list', 'view'])]
#[Column('detail', group: 'view')]
#[Column('compact', group: 'compact', sortable: true)]
class GroupedController extends AbstractCrudController
{
	public function getAction(Request $request, ?string $name = null): ?Action
	{
		return (new Action('list'))->setMethod('list');
	}

	public function filterForm(Request $request, EntityColumnViewGroupEnum|string|null $group = null): ?FormInterface
	{
		return $this->getFilterForm($request, $group);
	}

	public function query(Request $request, EntityColumnViewGroupEnum|string $group): QueryBuilder
	{
		return $this->createQueryBuilder($request, $group);
	}
}

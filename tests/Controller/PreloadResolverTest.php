<?php

namespace Dakataa\Crud\Tests\Controller;

use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewGroupEnum;
use Dakataa\Crud\Attribute\Resolver\ColumnValueResolver;
use Dakataa\Crud\Attribute\Resolver\EntityResolver;
use Dakataa\Crud\Attribute\Resolver\PreloadResolver;
use Dakataa\Crud\Attribute\Resolver\ResponseContextResolver;
use Dakataa\Crud\Controller\AbstractCrudController;
use Dakataa\Crud\Controller\CrudServiceContainer;
use Dakataa\Crud\Serializer\CrudSerializer;
use Dakataa\Crud\Service\CrudContext;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use UnexpectedValueException;

class PreloadResolverTest extends TestCase
{
	public function testViewPreloadsInDeclarationOrderBeforeColumnsAclAndResponseContext(): void
	{
		$request = $this->request('view');
		$controller = $this->controller($request);
		$response = $controller->view($request, 7);

		self::assertSame(['access', 'shared', 'second', 'method', 'column', 'acl', 'context'], $controller->calls);
		self::assertSame(['object' => $controller->object], $controller->preloadContext);
		self::assertSame($request, $controller->preloadRequest);
		$payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame('Loaded name', $payload['entity']['data']['name']);
		self::assertSame(['ready' => true], $payload['context']);
	}

	public function testListContextPreservesOnlySuppliedRowsScalarsAndPaginationMetadata(): void
	{
		$request = $this->request('list');
		$controller = $this->controller($request);
		$context = ['items' => [[$controller->object, 'total' => '12.50']], 'meta' => ['page' => 3, 'maxResults' => 10]];
		$this->preload($controller, $request, $context);

		self::assertSame(['shared'], $controller->calls);
		self::assertSame($context, $controller->preloadContext);
	}

	public function testEmptyResultsStillInvokeTheHookAndEachRequestRunsItAgain(): void
	{
		$first = $this->request('list');
		$controller = $this->controller($first);
		$this->preload($controller, $first, ['items' => [], 'meta' => ['page' => 1]]);
		$second = $this->request('list');
		$controller->setContext(new CrudContext($second));
		$this->preload($controller, $second, ['items' => [], 'meta' => ['page' => 2]]);

		self::assertSame(['shared', 'shared'], $controller->calls);
		self::assertSame($second, $controller->preloadRequest);
		self::assertSame(['items' => [], 'meta' => ['page' => 2]], $controller->preloadContext);
	}

	public function testExportPreloadsBeforeCompilingRowsAndDoesNotResolveResponseContext(): void
	{
		$request = $this->request('export');
		$controller = $this->controller($request);
		$response = $controller->export($request, AbstractCrudController::EXPORT_CSV);

		self::assertSame(['shared', 'column'], $controller->calls);
		self::assertSame(['items' => [$controller->object]], $controller->preloadContext);
		ob_start();
		try {
			$response->sendContent();
			$content = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		self::assertStringContainsString('Loaded name', $content);
	}

	public function testUnmatchedActionDoesNotInvokeResolvers(): void
	{
		$request = $this->request('edit');
		$controller = $this->controller($request);
		$this->preload($controller, $request, ['object' => $controller->object]);

		self::assertSame([], $controller->calls);
	}

	public function testDeniedViewDoesNotPreload(): void
	{
		$request = $this->request('view');
		$controller = $this->controller($request);
		$controller->allowed = false;
		try {
			$controller->view($request, 7);
			self::fail('Denied view must throw.');
		} catch (AccessDeniedException) {
			self::assertSame(['access'], $controller->calls);
		}
	}

	public function testMissingObjectDoesNotPreload(): void
	{
		$request = $this->request('view');
		$controller = $this->controller($request);
		$controller->object = null;
		try {
			$controller->view($request, 7);
			self::fail('Missing object must throw.');
		} catch (NotFoundHttpException) {
			self::assertSame([], $controller->calls);
		}
	}

	public function testReturnValuesAreRejectedBeforeLaterResolversRun(): void
	{
		$request = $this->request('view');
		$controller = $this->controller($request);
		$controller->invalidResult = true;
		try {
			$controller->view($request, 7);
			self::fail('A preload result must be rejected.');
		} catch (UnexpectedValueException $exception) {
			self::assertSame('Preload resolver must not return a value.', $exception->getMessage());
			self::assertSame(['access', 'shared'], $controller->calls);
		}
	}

	public function testFailuresPropagateBeforeAnyRowIsCompiled(): void
	{
		$request = $this->request('view');
		$controller = $this->controller($request);
		$controller->failure = new \RuntimeException('Batch load failed');
		try {
			$controller->view($request, 7);
			self::fail('Preload failures must propagate.');
		} catch (\RuntimeException $exception) {
			self::assertSame($controller->failure, $exception);
			self::assertSame(['access', 'shared'], $controller->calls);
		}
	}

	private function preload(PreloadController $controller, Request $request, array $context): void
	{
		(new ReflectionMethod(AbstractCrudController::class, 'resolvePreload'))->invoke($controller, $request, $context);
	}

	private function request(string $action): Request
	{
		$request = new Request(attributes: ['_controller' => PreloadController::class.'::'.$action, 'action' => $action]);
		$request->setRequestFormat('json');

		return $request;
	}

	private function controller(Request $request): PreloadController
	{
		$controller = new PreloadController();
		$controller->object = new PreloadEntity();
		$metadata = new ClassMetadata(PreloadEntity::class);
		$metadata->mapField(['fieldName' => 'id', 'type' => Types::INTEGER, 'id' => true]);
		$metadata->mapField(['fieldName' => 'name', 'type' => Types::STRING]);
		$metadata->initializeReflection(new RuntimeReflectionService());
		$metadata->wakeupReflection(new RuntimeReflectionService());
		$manager = $this->createStub(EntityManagerInterface::class);
		$manager->method('getClassMetadata')->willReturn($metadata);
		$query = $this->createStub(Query::class);
		$query->method('getResult')->willReturn([$controller->object]);
		$controller->queryBuilder = $this->createStub(QueryBuilder::class);
		$controller->queryBuilder->method('getQuery')->willReturn($query);
		$services = (new ReflectionClass(CrudServiceContainer::class))->newInstanceWithoutConstructor();
		$services->entityManager = $manager;
		$services->serializer = new CrudSerializer($this->createStub(RouterInterface::class));
		$services->templateProvider = null;
		$controller->setServiceContainer($services);
		$controller->setContext(new CrudContext($request));

		return $controller;
	}
}

class PreloadEntity
{
	public int $id = 7;
	public string $name = 'Loaded name';

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}
}

#[Entity(PreloadEntity::class)]
#[Column('id', searchable: false)]
#[Column('name', searchable: false)]
#[EntityResolver('findObject', actions: ['view'])]
#[ColumnValueResolver('columnValue', fields: ['name'])]
#[PreloadResolver('shared', actions: ['list', 'view', 'export'])]
#[PreloadResolver('second', actions: ['view'])]
#[ResponseContextResolver('responseContext')]
class PreloadController extends AbstractCrudController
{
	public array $calls = [];
	public array $preloadContext = [];
	public ?Request $preloadRequest = null;
	public ?PreloadEntity $object = null;
	public QueryBuilder $queryBuilder;
	public bool $allowed = true;
	public bool $ready = false;
	public bool $invalidResult = false;
	public ?\RuntimeException $failure = null;

	public function getAction(Request $request, ?string $name = null): ?Action
	{
		return (new Action($request->attributes->get('action')))->setMethod($request->attributes->get('action'));
	}

	#[PreloadResolver('methodPreload', actions: ['view'])]
	public function view(Request $request, int|string $id): ?Response
	{
		return parent::view($request, $id);
	}

	public function findObject(): ?PreloadEntity
	{
		return $this->object;
	}

	public function isActionAccessGranted(Request $request, Action $action, ?object $object = null): bool
	{
		$this->calls[] = 'access';
		return $this->allowed;
	}

	protected function createQueryBuilder(Request $request, EntityColumnViewGroupEnum|string $viewGroup = EntityColumnViewGroupEnum::List): QueryBuilder
	{
		return $this->queryBuilder;
	}

	public function shared(Request $request, Action $action, array $context, CrudServiceContainer $services): mixed
	{
		if ($services !== $this->serviceContainer || $action->name !== $request->attributes->get('action')) {
			throw new \LogicException('Unexpected resolver arguments.');
		}
		$this->calls[] = 'shared';
		if ($this->failure !== null) {
			throw $this->failure;
		}
		$this->ready = true;
		$this->preloadContext = $context;
		$this->preloadRequest = $request;

		return $this->invalidResult ? [] : null;
	}

	public function second(): void
	{
		$this->calls[] = 'second';
	}

	public function methodPreload(): void
	{
		$this->calls[] = 'method';
	}

	public function columnValue(Request $request, PreloadEntity $object): string
	{
		$this->assertReady();
		$this->calls[] = 'column';
		return $object->getName();
	}

	protected function getACLs(Request $request, array $items)
	{
		$this->assertReady();
		$this->calls[] = 'acl';
		return [];
	}

	public function responseContext(): array
	{
		$this->assertReady();
		$this->calls[] = 'context';
		return ['ready' => $this->ready];
	}

	private function assertReady(): void
	{
		if (!$this->ready) {
			throw new \LogicException('Preload must run before row processing.');
		}
	}
}

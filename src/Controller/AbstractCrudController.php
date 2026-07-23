<?php

namespace Dakataa\Crud\Controller;

use BackedEnum;
use Closure;
use Dakataa\Crud\Attribute\ACL;
use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\ColumnValueResolver;
use Dakataa\Crud\Attribute\EntityFinder;
use Dakataa\Crud\Attribute\EntityGroup;
use Dakataa\Crud\Attribute\EntityJoinColumn;
use Dakataa\Crud\Attribute\EntitySort;
use Dakataa\Crud\Attribute\EntityType;
use Dakataa\Crud\Attribute\Enum\ActionVisibilityEnum;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewGroupEnum;
use Dakataa\Crud\Attribute\PathParameterToFieldMap;
use Dakataa\Crud\Attribute\QueryParameterToFieldMap;
use Dakataa\Crud\Attribute\SearchableOptions;
use Dakataa\Crud\Security\SecuritySubject;
use Dakataa\Crud\Service\CrudContext;
use Dakataa\Crud\Twig\TemplateProvider;
use Dakataa\Crud\Utils\Doctrine\Paginator;
use Dakataa\Crud\Utils\StringHelper;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\Persistence\Proxy;
use Exception;
use Generator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Html;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Stringable;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Service\Attribute\Required;
use TypeError;


abstract class AbstractCrudController implements CrudControllerInterface
{
	const ENTITY_ROOT_ALIAS = 'a';

	const EXPORT_EXCEL = 'excel';
	const EXPORT_EXCEL2007 = 'excel2007';
	const EXPORT_CSV = 'csv';
	const EXPORT_HTML = 'html';

	const MAX_RESULTS_LIMIT_DEFAULT = 10;
	const HARD_MAX_RESULTS_LIMIT = 100;

	const COMPOSITE_IDENTIFIER_SEPARATOR = '-';

	protected CrudContext|null $context = null;

	protected CrudServiceContainer $serviceContainer;

	/**
	 * @var array<Entity> $entity
	 */
	protected array $entity = [];

	protected ?ClassMetadata $entityClassMetadata = null;

	protected ?array $actions = null;

	private ?ExpressionLanguage $expressionLanguage = null;

	private array $reflectionCache = [];


	protected function getPHPAttributes(string $attributeFQCN, string $method = null): array
	{
		$reflectionClass = $this->getReflectionClass($this->getControllerClass());

		return array_map(
			fn(ReflectionAttribute $attribute) => $attribute->newInstance(),
			($method ? $reflectionClass->getMethod($method) : $reflectionClass)->getAttributes($attributeFQCN)
		);
	}

	protected function getPHPAttribute(string $attributeClass, string $method = null): mixed
	{
		return current($this->getPHPAttributes($attributeClass, $method)) ?: null;
	}

	public function setContext(CrudContext $context): void
	{
		$this->context = $context;
		$this->entity[$context->method] ??= $this->resolveEntity($context->method);

		$this->entityClassMetadata = null;
		$this->actions = null;
	}

	private function resolveEntity(string $method): ?Entity
	{
		return $this->entity[$method] ??= $this->hydrateEntity($method);
	}

	private function hydrateEntity(string $method): ?Entity
	{
		$actionMethodEntity = $this->getPHPAttribute(Entity::class, $method);
		if (!$actionMethodEntity) {
			$method = null;
		}

		$entity = $actionMethodEntity ?: $this->getPHPAttribute(Entity::class);

		if (empty($entity?->joins)) {
			$entity?->setJoins($this->getPHPAttributes(EntityJoinColumn::class, $method));
		}

		if (empty($entity?->group)) {
			$entity?->setGroup($this->getPHPAttributes(EntityGroup::class, $method));
		}

		if (empty($entity?->sort)) {
			$entity?->setSort($this->getPHPAttributes(EntitySort::class, $method));
		}

		if (empty($entity?->columns)) {
			$entity?->setColumns($this->getPHPAttributes(Column::class, $method));
		}

		return $entity;
	}

	#[Required]
	public function setServiceContainer(CrudServiceContainer $loader): void
	{
		$this->serviceContainer = $loader;
	}

	final public function getEntity(bool $required = false): Entity|null
	{
		$entity = $this->entity[$this->context?->method] ?? null;

		if ($required && !$entity) {
			throw new Exception('Entity not set.');
		}

		return $entity;
	}

	public function getEntityType(): ?EntityType
	{
		$entityType = $this->getPHPAttributes(EntityType::class, $this->context->method) ?: $this->getPHPAttributes(
			EntityType::class
		);

		return current(
			array_values(
				array_filter(
					$entityType,
					fn(EntityType $t) => in_array($t->action, [$this->context->method, null])
				)
			)
		) ?: null;
	}

	protected function compileEntityData(
		Request $request,
		array|object $object,
		EntityColumnViewGroupEnum|string $viewGroup = null,
		bool|null $useFlatKeys = null
	): array {
		$additionalEntityFields = [];
		if (is_array($object)) {
			if (empty($object[0])) {
				throw new Exception('Invalid results.');
			}

			if ($object[0] instanceof Proxy) {
				$objectClass = get_parent_class($object[0]);
			}

			$objectClass = $object[0]::class;

			if ($objectClass === $this->getEntity(true)->getFqcn()) {
				$additionalEntityFields = array_filter(
					$object,
					fn(mixed $key) => !is_numeric($key),
					ARRAY_FILTER_USE_KEY
				);
				$object = $object[0];
			} else {
				throw new Exception('Invalid results.');
			}
		}


		$columnValueResolver = $this->getColumnResolver();

		$getValue = function (object|null $object, string $field, Column $column) use ($request, $additionalEntityFields, $columnValueResolver) {
			if (!$object) {
				return null;
			}

			if ($column->getPermission() && false === $this->isAccessGranted($column->getPermission(), $object)) {
				return null;
			}


			$value = null;
			if ($columnValueResolverCallable = $columnValueResolver?->getCallable($this->getResolverContext(), $column)) {
				$value = call_user_func($columnValueResolverCallable, $request, $object, $column, $this->serviceContainer);
			} elseif ($getter = $column->getGetter()) {
				if (is_string($getter)) {
					$getter = sprintf('get%s', (preg_replace('/^get/i', '', Container::camelize($getter))));

					if (method_exists($object, $getter)) {
						$value = $object->$getter();
					}
				}

				if ($getter instanceof Closure) {
					$value = $getter($value);
				}
			} else {
				if (array_key_exists($column->getAlias(), $additionalEntityFields)) {
					$value = $additionalEntityFields[$column->getAlias()];
				} else {
					foreach (['get', 'has', 'is'] as $methodPrefix) {
						$method = sprintf(
							'%s%s',
							$methodPrefix,
							Container::camelize(Container::underscore($field))
						);

						if (method_exists($object, $method)) {
							$value = $object->$method();
							break;
						}
					}
				}
			}

			if ($value instanceof Collection) {
				$value = new class(array_map(fn(Stringable $v) => $v->__toString(), $value->toArray())) extends ArrayCollection {
					public function __toString()
					{
						return implode(', ', $this->getValues());
					}
				};
			}

			if ($value instanceof DateTimeInterface) {
				$value = $value->format($column->getOption('dateFormat') ?: DateTimeInterface::ATOM);
			}

			if ($value instanceof BackedEnum) {
				$value = $value->value;
			}

			if (is_array($value) && false === $column->isRaw()) {
				$value = json_encode($value);
			}

			if (is_object($value)) {
				if ($value instanceof Stringable) {
					$value = $value->__toString();
				} else {
					if (false === $column->isRaw()) {
						$value = json_encode($value);
					}
				}
			}

			try {
				$enum = $column->getEnum() ?: [];
				$value = $enum[$value] ?? $value;
			} catch (TypeError $e) {
			}

			return $value;
		};

		$result = [];
		foreach ($this->getEntityColumns($viewGroup, includeIdentifier: true) as $column) {
			$dataObject = $object;
			$fieldPath = explode('.', $column->getField());

			foreach ($fieldPath as $fieldAlias) {
				$classMetaData = $this->serviceContainer->entityManager->getClassMetadata($dataObject::class);
				if (false === $classMetaData->hasAssociation($fieldAlias)) {
					break;
				}

				$associationMapping = $classMetaData->getAssociationMapping($fieldAlias);
				if($associationMapping->isToMany()) {
					break;
				}

				$accessor = PropertyAccess::createPropertyAccessor();
				$associationDataObject = $accessor->getValue($dataObject, $fieldAlias);


				if (null === $associationDataObject) {
					break;
				}

				$dataObject = $associationDataObject;
			}

			$value = $getValue($dataObject, $fieldAlias ?? $column->getField(), $column);
			$columnUseFlatKeys = $useFlatKeys === null ? $column->isUseFlatKey() : $useFlatKeys;

			if ($columnUseFlatKeys) {
				if (array_key_exists($column->getField(), $result)) {
					throw new Exception(
						sprintf(
							'Column field "%s" conflicts with another column using "%s" as a scalar key.',
							$column->getField(),
							$fieldAlias ?? $column->getField()
						)
					);
				}

				$result[$column->getField()] = $value;
			} else {
				$newResult = &$result;

				foreach ($fieldPath as $index => $fieldAlias) {
					$isLastField = count($fieldPath) === ($index + 1);

					if (!array_key_exists($fieldAlias, $newResult)) {
						$newResult[$fieldAlias] = $isLastField ? $value : [];
					} else {
						if (!is_array($newResult[$fieldAlias])) {
							throw new Exception(
								sprintf(
									'Column field "%s" conflicts with another column using "%s" as a scalar key.',
									$column->getField(),
									$fieldAlias
								)
							);
						}

						if ($isLastField) {
							throw new Exception(
								sprintf(
									'Column field "%s" conflicts with another column using "%s" as a nested key.',
									$column->getField(),
									$fieldAlias
								)
							);
						}
					}

					if (!$isLastField) {
						$newResult = &$newResult[$fieldAlias];
					}
				}
			}
		}

		return $result;
	}

	protected function getACLs(Request $request, array $items)
	{
		$aclAttribute = $this->getPHPAttribute(ACL::class);
		$permissions = array_merge(
			array_unique(array_filter(array_map(fn(Action $action) => $action->permission, $this->getActions($request)))
			),
			$aclAttribute->permissions ?? []
		);

		return array_reduce(
			$permissions,
			function (array $result, string $permission) use ($items) {
				return array_filter([
					...$result,
					$permission => array_values(
						array_filter(
							array_map(
								fn($object) => $this->isAccessGranted(
									$permission,
									$object
								) ? $this->getEntityIdentifierValueFromObject($object) : null,
								$items
							)
						)
					),
				]);
			},
			[]
		);
	}

	/**
	 * @throws ExceptionInterface
	 */
	#[
		Route,
		Action(options: ['pagination' => true, 'batch' => true]),
	]
	public function list(Request $request): Response
	{
		$action = $this->getAction($request);
		if (!$action) {
			throw new Exception('This Action "list" is not enabled in the list of Entity Actions.');
		}

		if (!$this->isActionAccessGranted($request, $action)) {
			throw new AccessDeniedException();
		}

		$filterForm = $this->getFilterForm($request);
		$batchForm = $this->handleBatch($request);
		if ($batchForm instanceof Response) {
			return $batchForm;
		}

		$queryViewGroup = $request->query->get('viewGroup');
		$viewGroup = EntityColumnViewGroupEnum::tryFrom($queryViewGroup ?: 'list') ?: $queryViewGroup ?: EntityColumnViewGroupEnum::List;
		$sorting = $this->prepareSorting($request);
		$paginator = new Paginator(
			$this->createQueryBuilder($request),
			$request->query->getInt('page', 1),
			$this->getEntity()?->isPagination() && (count(
					$this->getEntityClassMetadata()->getIdentifierFieldNames()
				) === 1) ? $this->prepareMaxResults($request) : null,
			static::HARD_MAX_RESULTS_LIMIT
		);

		['items' => $items, 'meta' => $meta] = $paginator->paginate();
		$items = iterator_to_array($items);

		return $this->response($request, [
			'title' => $action->title ?: StringHelper::titlize($this->getEntityShortName()),
			'entity' => [
				'name' => $this->getEntityShortName(),
				'primaryColumn' => $this->getEntityPrimaryColumn(),
				'columns' => iterator_to_array($this->getEntityColumns($viewGroup)),
				'data' => [
					'items' => array_map(
						fn(array|object $object) => $this->compileEntityData($request, $object, $viewGroup),
						$items
					),
					'meta' => $meta,
				],
				'acl' => $this->getACLs($request, array_map(fn(array $item) => $item[0], $items)),
			],
			'form' => [
				...($filterForm ? [
					'filter' => [
						'view' => $filterForm->createView(),
					],
				] : []),
				...($batchForm ? [
					'batch' => [
						'view' => $batchForm->createView(),
					],
				] : []),
			],
			'sort' => $sorting,
			'action' => $this->getActions($request, true),
		], defaultTemplate: 'list');
	}

	/**
	 * @throws Exception
	 */
	#[
		Route(path: '/export/{type}'),
		Action
	]
	public function export(
		Request $request,
		string $type = self::EXPORT_EXCEL
	): StreamedResponse {
		$action = $this->getAction($request);
		$exportTypes = [
			self::EXPORT_EXCEL => ['ext' => 'xlsx', 'writer' => Xlsx::class],
			self::EXPORT_EXCEL2007 => ['ext' => 'xls', 'writer' => Xls::class],
			self::EXPORT_HTML => ['ext' => 'html', 'writer' => Html::class],
			self::EXPORT_CSV => ['ext' => 'csv', 'writer' => Csv::class],
		];

		if (!array_key_exists($type, $exportTypes)) {
			throw new Exception(
				'Invalid Export Type '.$type.' (Available: '.implode(', ', array_keys($exportTypes)).')'
			);
		}
		$exportType = $exportTypes[$type];
		if (!class_exists($exportType['writer'])) {
			throw new Exception(sprintf('Missing Export Writer: %s', $exportType['writer']));
		}
		$queryBuilder = $this->createQueryBuilder($request, EntityColumnViewGroupEnum::Export);

		$objects = $queryBuilder->getQuery()->getResult();

		//Excel
		$spreadsheet = new Spreadsheet();
		$spreadsheet
			->getProperties()
			->setCreator(sprintf('Auto generated: %s', 'Dakataa CRUD'))
			->setTitle('Export')
			->setCompany($action?->title ?: 'Export');

		//Header
		$header = [];
		/** @var Column $column */
		foreach ($this->buildColumns(EntityColumnViewGroupEnum::Export) as ['column' => $column]) {
			$header[] = $column->getLabel();
		}
		$rows = [$header];
		//Rows
		foreach ($objects as $object) {
			$rows[] = $this->compileEntityData($request, $object, EntityColumnViewGroupEnum::Export, true);
		}

		try {
			$spreadsheet
				->setActiveSheetIndex(0);
		} catch (Exception) {
			$spreadsheet->createSheet();
			$spreadsheet->setActiveSheetIndex(0);
		}

		$worksheet = $spreadsheet
			->getActiveSheet()
			->fromArray($rows)
			->setTitle('Data');

		$worksheet->getProtection()->setSheet(true);
		$cellIterator = $worksheet->getRowIterator()->current()->getCellIterator();
		$cellIterator->setIterateOnlyExistingCells(true);
		foreach ($cellIterator as $cell) {
			$worksheet->getColumnDimension($cell->getColumn())->setAutoSize(true)->setCollapsed(true);
			$cell->getStyle()->getFont()->setBold(true);
		}
		$writer = new $exportType['writer']($spreadsheet);

		return new StreamedResponse(fn() => $writer->save('php://output'), headers: [
			'Content-Type' => 'application/force-download',
			'Content-Disposition' => 'attachment; filename="export.'.$exportType['ext'].'"',
		]);
	}

	/**
	 * @throws Exception
	 */
	#[Route(path: '/add')]
	#[Action]
	public function add(Request $request, #[MapQueryParameter] bool $save = null): ?Response
	{
		return $this->modify($request, $this->getAction($request), save: $save ?: true);
	}

	/**
	 * @throws Exception
	 */
	#[Route(path: '/{id}/view')]
	#[Action(visibility: ActionVisibilityEnum::Object)]
	public function view(Request $request, int|string $id): ?Response
	{
		$action = $this->getAction($request);
		$entityFinderObject = $this->getEntityWithFinder($action);
		if ($entityFinderObject !== false) {
			$object = $entityFinderObject;
		} else {
			$object = $this->getEntityRepository()->find($this->getEntityIdentifierPrepare($id));
		}

		if (empty($object)) {
			throw new NotFoundHttpException('Not Found');
		}

		if (!$this->isActionAccessGranted($request, $action, $object)) {
			throw new AccessDeniedException();
		}

		$queryViewGroup = $request->query->get('viewGroup');
		$viewGroup = EntityColumnViewGroupEnum::tryFrom($queryViewGroup ?: 'view') ?: $queryViewGroup ?: EntityColumnViewGroupEnum::View;

		return $this->response($request, [
			'entity' => [
				'name' => $this->getEntityShortName(),
				'primaryColumn' => $this->getEntityPrimaryColumn(),
				'columns' => iterator_to_array($this->getEntityColumns($viewGroup)),
				'data' => $this->compileEntityData($request, $object, $viewGroup),
				'acl' => $this->getACLs($request, [$object]),
			],
			'title' => $action?->title,
		], defaultTemplate: 'view');
	}

	final protected function getMappedFields(Request $request, Action $action): Generator
	{
		/** @var PathParameterToFieldMap[] $mappedParameters */
		$mappedParameters = [
			...$this->getPHPAttributes(QueryParameterToFieldMap::class),
			...$this->getPHPAttributes(QueryParameterToFieldMap::class, $action->name),
			...$this->getPHPAttributes(PathParameterToFieldMap::class),
			...$this->getPHPAttributes(PathParameterToFieldMap::class, $action->name),
		];

		foreach ($mappedParameters as $mappedPathParameter) {
			$fieldName = $mappedPathParameter->getField();
			$column = $this->buildColumn(new Column($fieldName));
			if (!$column) {
				throw new Exception(sprintf('Invalid field mapping for %s', $fieldName));
			}

			if ($this->getEntity(true)->getFqcn() !== $column['fqcn']) {
				$entityMetadata = $this->serviceContainer->entityManager->getClassMetadata($column['fqcn']);
			} else {
				$entityMetadata = $this->getEntityClassMetadata();
			}

			$columnName = $column['entityField'];
			$fieldValue = $request->get($mappedPathParameter->getParameter()) ?: $request->attributes->get(
				'_route_params'
			)[$mappedPathParameter->getParameter()] ?? null;

			if ($fieldValue && $entityMetadata->hasAssociation($columnName)) {
				$associationClassName = $entityMetadata->getAssociationTargetClass($columnName);
				if (null === $fieldValue = $this->serviceContainer->entityManager->getRepository($associationClassName)->find($fieldValue)) {
					throw new Exception(
						sprintf('Cannot found "%s" association with PK %s', $columnName, $fieldValue)
					);
				}
			}

			yield $fieldName => $fieldValue;
		}
	}

	private function getEntityWithFinder(Action|null $action = null): object|null|false
	{
		if (!$this->context) {
			throw new Exception('Context is not set.');
		}

		$request = $this->context->request;
		$action ??= $this->getAction($request);

		if (null === $entityFinder = $this->getPHPAttribute(EntityFinder::class, $action->name)) {
			return false;
		}

		$finder = $entityFinder->finder;
		$resolverContext = $this->getResolverContext();
		$object = match (true) {
			is_string($finder) && class_exists($finder) => (new $finder())($request, $this->serviceContainer),
			is_string($finder) && method_exists($resolverContext, $finder) => (new ReflectionMethod(
				$resolverContext,
				$finder
			))->getClosure($resolverContext)($request, $this->serviceContainer),
			is_callable($finder) => call_user_func($finder, $request, $this->serviceContainer),
			default => throw new NotFoundHttpException('Invalid Entity Finder. Class or Method not found.'),
		};

		if ($object && false === is_a($object, $this->getEntity(true)->getFqcn(), true)) {
			throw new NotFoundHttpException('Invalid Entity Finder. Method must return an object of the same class.');
		}

		return $object;
	}


	private function getColumnResolver(Action|null $action = null): ColumnValueResolver|null
	{
		if (!$this->context) {
			throw new Exception('Context is not set.');
		}

		$request = $this->context->request;
		$action ??= $this->getAction($request);

		return $this->getPHPAttribute(ColumnValueResolver::class, $action->name) ?: $this->getPHPAttribute(ColumnValueResolver::class);
	}

	final protected function modify(
		Request $request,
		Action $action = null,
		mixed $id = null,
		bool $save = true
	): ?Response {
		if (!$action) {
			throw new Exception('This Action is not enabled in the list of Entity Actions.');
		}

		if (!$this->getEntityType()) {
			throw new NotFoundHttpException('Not Entity Type found.');
		}

		$messages = [];
		$object = null;

		$entityFinderObject = $this->getEntityWithFinder($action);
		if ($entityFinderObject !== false) {
			$object = $entityFinderObject;
		} else {
			if ($id) {
				$object = $this->getEntityRepository()->find($this->getEntityIdentifierPrepare($id));
			}
		}

		if ($this->getEntity() && empty($object)) {
			if ($this->getEntityClassMetadata()->generatorType === ClassMetadata::GENERATOR_TYPE_NONE) {
				throw new Exception('Entity ID Generator is disabled.');
			}

			if (false !== $object = $this->findEntityObjectByRequest($request, $action)) {
				if (!is_a($object, $this->getEntity(true)->getFqcn(), true)) {
					throw new NotFoundHttpException('Not Found');
				}
			} else {
				$object = new ($this->getEntityClassMetadata()->getName());
				foreach ($this->getMappedFields($request, $action) as $fieldName => $fieldValue) {
					if (!$this->getEntityClassMetadata()->hasField($fieldName) && !$this->getEntityClassMetadata()->hasAssociation($fieldName)) {
						continue;
					}

					$this->getEntityClassMetadata()->setFieldValue($object, $fieldName, $fieldValue);
				}
			}
		}

		if (!$this->isActionAccessGranted($request, $action, $object)) {
			throw new AccessDeniedException();
		}

		// Form type options
		$formOptions = array_merge_recursive([
			'action' => $request->getUri(),
			'method' => Request::METHOD_POST,
			'csrf_protection' => false,
		], $this->buildFormTypeOptions($request, $action, $this->getEntityType()?->getOptions() ?: []));

		$this->onFormTypeBeforeCreate($request, $object, $action);
		$form = $this->serviceContainer->formFactory->create(
			$this->getEntityType()?->getFqcn(),
			$object,
			$formOptions
		);

		$this->onFormTypeCreate($request, $action, $form, $object);
		$responseStatus = 200;
		if ($request->isMethod(Request::METHOD_POST)) {
			$form->handleRequest($request);

			// Allow submitting empty forms
			if (false === $form->isSubmitted()) {
				$form->submit([]);
			}

			if ($form->isSubmitted() && $form->isValid() && $save) {
				$this->beforeFormSave($request, $form);
				if ($this->getEntity()) {
					$this->serviceContainer->entityManager->persist($form->getData());
					$this->serviceContainer->entityManager->flush();
				}

				$object = $form->getData();

				$this->afterFormSave($request, $form);

				$messages = [
					'success' => [
						$this->getEntityType()?->getSuccessMessage() ?: 'Item was saved successfully',
					],
				];

				if ($action->getRoute()) {
					$route = $this->serviceContainer->router->getRouteCollection()->get(
						$action->getRoute()->getName()
					);
					$routeVariables = $route->compile()->getPathVariables();

					$redirect = [
						'route' => $this->serviceContainer->router->getRouteCollection()->get(
							$action->getRoute()->getName()
						),
						'parameters' => [
							'id' => $this->getEntityIdentifierValueFromObject($object),
							...(array_intersect_key($request->attributes->all(), array_flip($routeVariables))),
						],
					];

					$redirect['url'] = $this->serviceContainer->router->generate(
						$action->getRoute()->getName(),
						$redirect['parameters']
					);

					if ($request->getPreferredFormat() === 'html') {
						return new RedirectResponse($redirect['url']);
					}
				}
			} else {
				$responseStatus = 400;
			}
		}

		$id ??= $this->getEntityIdentifierValueFromObject($object);

		return $this->response($request, [
			'title' => $action->title ?: ($id ? 'Edit' : 'New'),
			...($id ? ['object' => $this->compileEntityData($request, $object)] : []),
			'form' => [
				'modify' => [
					'view' => $form->createView(),
				],
			],
			'messages' => $messages,
			...(isset($redirect) ? [
				'redirect' => $redirect,
			] : []),
		], $responseStatus, defaultTemplate: 'edit');
	}

	/**
	 * @throws Exception
	 */
	#[Route(path: '/{id}/edit')]
	#[Action(visibility: ActionVisibilityEnum::Object)]
	public function edit(Request $request, mixed $id = null, #[MapQueryParameter] bool $save = null): ?Response
	{
		return $this->modify($request, $this->getAction($request), $id, $save ?: true);
	}

	#[Route(path: '/{id}/delete', methods: ['DELETE', 'OPTIONS'])]
	#[Action(visibility: ActionVisibilityEnum::Object)]
	public function delete(Request $request, int|string $id): Response
	{
		$action = $this->getAction($request);
		if (!$action) {
			throw new Exception('This Action "delete" is not enabled in the list of Entity Actions.');
		}

		if ($request->isMethod(Request::METHOD_OPTIONS)) {
			return new Response;
		}

		$object = $this->getEntityRepository()->find($this->getEntityIdentifierPrepare($id));
		if (!$this->isActionAccessGranted($request, $action, $object)) {
			throw new AccessDeniedException();
		}

		if ($object) {
			$this->batchDelete($request, [$object]);
		}

		$attributeRoute = $this->getRoute($request, 'list');
		$route = $this->serviceContainer->router->getRouteCollection()->get($attributeRoute->getName());
		$routeVariables = $route->compile()->getPathVariables();

		return new RedirectResponse($this->serviceContainer->router->generate($attributeRoute->getName(), [
			...array_intersect_key($request->attributes->all(), array_flip($routeVariables)),
		]));
	}

	protected function handleBatch(Request $request): Response|FormInterface|null
	{
		if (!$this->getEntity()?->batch) {
			return null;
		}

		$form = $this->getBatchForm($request);
		if ($request->isMethod(Request::METHOD_POST)) {
			$form->handleRequest($request);
			if ($form->isSubmitted() && $form->isValid()) {
				$method = Container::camelize('batch_'.Container::underscore($form->get('method')->getData()));

				if (!method_exists($this, $method)) {
					throw new Exception(sprintf('Method %s not exists', $method));
				}

				$ids = array_map(fn(mixed $id) => $this->getEntityIdentifierPrepare($id), $form->get('ids')->getData());
				$query = $this
					->getEntityRepository()
					->createQueryBuilder(self::ENTITY_ROOT_ALIAS);

				$criteria = $query->expr()->orX();

				foreach ($ids as $id) {
					$criteria->add(
						$query->expr()->andX(
							...array_map(
								fn(string $k) => $query->expr()->eq(
									sprintf('%s.%s', self::ENTITY_ROOT_ALIAS, $k),
									$id[$k]
								),
								array_keys($id)
							)
						)
					);
				}

				$objects = $query->where($criteria)->getQuery()->getResult();
				if ($response = $this->$method($request, $objects)) {
					if ($response instanceof Response) {
						return $response;
					}
				}
			}
		}

		return $form;
	}

	protected function batchDelete(Request $request, array $objects): void
	{
		if (count($objects)) {
			foreach ($objects as $object) {
				$this->serviceContainer->entityManager->remove($object);
			}

			$this->serviceContainer->entityManager->flush();
			if ($request->hasSession()) {
				$request->getSession()->getFlashBag()->add('notice', 'Items was deleted successfully!');
			}
		}
	}

	public function getControllerClass(): string
	{
		return static::class;
	}

	public function getResolverContext(): object
	{
		return $this;
	}


	/**
	 * @throws ExceptionInterface
	 */
	protected function response(
		Request $request,
		array $data,
		int $status = 200,
		string $defaultTemplate = null
	): Response {
		[, $template] = explode('::', $request->get('_controller'));

		$format = $this->serviceContainer->templateProvider ? $request->getPreferredFormat() : 'json';
		switch ($format) {
			case 'json':
			{
				return new JsonResponse(
					$this->serviceContainer->serializer->normalize($data), $status
				);
			}
			default:
				return new Response(
					$this->serviceContainer->templateProvider?->render($this, $template, $data, $defaultTemplate),
					$status
				);
		}
	}

	protected function getBatchForm(Request $request): FormInterface|null
	{
		if (!$this->getEntity()->batch) {
			return null;
		}

		$form = $this
			->serviceContainer
			->formFactory
			->createNamedBuilder('batch_'.Container::underscore($this->getEntityShortName()), options: [
				...($this->serviceContainer->parameterBag->get(
					'form.type_extension.csrf.enabled'
				) ? ['csrf_protection' => false] : []),
			]);

		$form
			->setMethod('POST')
			->add('ids', CollectionType::class, [
				'required' => true,
				'allow_add' => true,
				'constraints' => [
					new Assert\NotBlank(),
				],
			]);

		$methods = array_reduce(
			array_filter(
				array_map(
					fn(ReflectionMethod $reflectionMethod) => Container::underscore(
						str_replace('batch', '', $reflectionMethod->getShortName())
					),
					array_filter(
						$this->getReflectionClass($this->getControllerClass())->getMethods(
							ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
						),
						fn(ReflectionMethod $reflectionMethod) => str_starts_with(
							$reflectionMethod->getShortName(),
							'batch'
						)
					)
				)
			),
			fn(array $result, string $action) => [
				...$result,
				[
					'action' => $action,
					'label' => $action,
				],
			],
			[]
		);

		$choices = [];
		foreach ($methods as $method) {
			['action' => $action, 'label' => $label] = $method;

//			$method = sprintf('batch%s', Container::camelize(Container::underscore($action)));
//			// TODO
//			if (!method_exists($this, $method) || !$this->secureControllerManager->hasPermission(
//					$this::class,
//					$method
//				)) {
//				continue;
//			}

			$choices[$label] = $action;
		}

		if (!empty($choices)) {
			$form->add(
				'method',
				ChoiceType::class,
				['choices' => $choices, 'placeholder' => '', 'constraints' => [new Assert\NotBlank()]]
			);
		}

		return $form->getForm();
	}

	/**
	 * @throws Exception
	 */
	protected function getFilterForm(Request $request): FormInterface|null
	{
		if (!$this->getEntity()?->filter) {
			return null;
		}

		$formBuilder = $this->serviceContainer->formFactory->createNamedBuilder(
			'filter',
			FormType::class,
			null,
			[
				...($this->serviceContainer->parameterBag->get(
					'form.type_extension.csrf.enabled'
				) ? ['csrf_protection' => false] : []),
			]
		)->setMethod(Request::METHOD_GET);

		foreach ($this->buildColumns(searchable: true) as $columnData) {
			[
				'fqcn' => $fqcn,
				'type' => $type,
				'column' => $column,
			] = $columnData;

			if (false === $column->getSearchable()) {
				continue;
			}

			$formFieldKey = $column->getAlias();
			$columnOptions = [
				'label' => $column->getLabel(),
				'required' => false,
			];

			$entityType = $type ?? TextType::class;
			if ($column->getSearchable() instanceof SearchableOptions) {
				$columnOptions = [
					...$columnOptions,
					...($column->getSearchable()->getOptions() ?: []),
				];

				$entityType = $column->getSearchable()->getType() ?: $entityType;
			}

			switch ($entityType) {
				case Types::INTEGER:
				case Types::BIGINT:
				case Types::TEXT:
				case Types::STRING:
				case Types::DECIMAL:
				case Types::SMALLINT:
					$formBuilder->add($formFieldKey, TextType::class, $columnOptions);
					break;
				case Types::DATE_MUTABLE:
				case Types::DATETIME_MUTABLE:
					$formBuilder->add($formFieldKey, DateType::class, [
						'placeholder' => '',
						...$columnOptions,
					]);
					break;
				case Types::BOOLEAN:
					$formBuilder->add($formFieldKey, ChoiceType::class, [
						'choices' => [
							'Yes' => true,
							'No' => false,
						],
						'placeholder' => 'All',
						...$columnOptions,
					]);
					break;
				default:
					if (empty($entityType) || !is_string($entityType)) {
						$entityType = null;
					}

					$formBuilder->add(
						$formFieldKey,
						class_exists($entityType) && is_a(
							$entityType,
							FormTypeInterface::class,
							true
						) ? $entityType : TextType::class,
						$columnOptions
					);
			}
		}

		$form = $formBuilder->getForm();
		$form->handleRequest($request);

		return $form;
	}

	protected function getDefaultSort(Request $request): ?array
	{
		return array_reduce($this->getEntity()?->sort ?: [], fn(array $result, EntitySort $sort) => [
			...$result,
			$sort->field => $sort->sort->value,
		], []);
	}

	protected function prepareSorting(
		Request $request,
		EntityColumnViewGroupEnum|string $viewGroup = null
	): array {
		$sorting = $request->query->all('sort') ?: array_filter(
			$request->getSession()->get(
				$this->getAlias().'.sort',
				[]
			)
		) ?: $this->getDefaultSort($request);

		$sorting = array_filter($sorting, fn($v) => in_array(Order::tryFrom(strtoupper($v)), Order::cases()));
		$columns = array_reduce(
			array_filter(
				iterator_to_array($this->buildColumns($viewGroup)),
				fn(array $c) => $c['column']->getSortable() !== false
			),
			fn(array $c, array $item) => [...$c, $item['column']->getField() => $item],
			[]
		);

		$sorting = array_intersect_key($sorting, $columns) + array_fill_keys(array_keys($columns), null);

		if ($request->hasSession()) {
			$request->getSession()->set($this->getAlias().'.sort', $sorting);
		}

		return $sorting;
	}

	public function prepareMaxResults(Request $request): int
	{
		$limit = ($request->hasSession() ? intval(
			$request->getSession()->get($this->getAlias().'.limit')
		) : null) ?: self::MAX_RESULTS_LIMIT_DEFAULT;
		$limit = min(
			self::HARD_MAX_RESULTS_LIMIT,
			max(
				self::MAX_RESULTS_LIMIT_DEFAULT,
				$request->query->getInt('limit', $limit)
			)
		);

		if ($request->hasSession()) {
			$request->getSession()->set($this->getAlias().'.limit', $limit);
		}

		return $limit;
	}

	protected function buildCustomQuery(Request $request, Action $action, QueryBuilder $query): void
	{
	}

	private function buildColumn(Column $column): array|false
	{
		$fieldName = $column->getField();
		$entityMetadata = $this->getEntityClassMetadata();
		$entityAlias = self::ENTITY_ROOT_ALIAS;
		$relations = [];

		if (str_contains($fieldName, '.')) {
			$entityRelations = explode('.', $fieldName);
			$fieldName = array_pop($entityRelations);

			$entityAlias = null;
			foreach ($entityRelations as $entityRelation) {
				if (!$entityMetadata->hasAssociation($entityRelation)) {
					return false;
				}

				$associationMapping = $entityMetadata->getAssociationMapping($entityRelation);

				$rootAlias = $entityAlias;
				$entityAlias = $entityAlias.Container::camelize($entityRelation);
				$relations[] = [
					'entity' => lcfirst($rootAlias ?: self::ENTITY_ROOT_ALIAS),
					'field' => $entityRelation,
					'alias' => lcfirst($entityAlias),
				];

				$entityMetadata = $this->serviceContainer->entityManager->getClassMetadata(
					$associationMapping['targetEntity']
				);
			}
		} else {
			if (
				false === $column->getSearchable() &&
				false === $entityMetadata->hasField($fieldName) &&
				false === $entityMetadata->hasAssociation($fieldName) &&
				(!$entityMetadata->isIdentifierComposite && $fieldName !== 'compositeId')
			) {
				return false;
			}
		}

		if ($column->getSortable()) {
			if (!$entityMetadata->hasField($fieldName)) {
				$column->setSortable(false);
			}
		}

		if ($column->getSearchable() !== false) {
			if (false === $entityMetadata->hasField($fieldName) && false === ($column->getSearchable() instanceof SearchableOptions)) {
				$column->setSearchable(false);
			}
		}

		return [
			'fqcn' => $entityMetadata->getReflectionClass()->name,
			'entityAlias' => lcfirst($entityAlias),
			'entityField' => $fieldName,
			'relations' => $relations,
			'type' => $entityMetadata->getTypeOfField($fieldName),
			'nullable' => $entityMetadata->hasField($fieldName) ? $entityMetadata->isNullable($fieldName) : null,
			'column' => $column,
			'canSelect' => $entityMetadata->hasField($fieldName) && false === $entityMetadata->hasAssociation(
					$fieldName
				),
		];
	}

	private function buildColumns(
		EntityColumnViewGroupEnum|string $viewGroup = null,
		bool|null $searchable = null,
		bool $includeIdentifier = false,
	): Generator {
		foreach ($this->getEntityColumns($viewGroup, $searchable, $includeIdentifier) as $column) {
			if ($searchable && (($searchableField = $column->getSearchable()) instanceof SearchableOptions)) {
				$hasSearchableFieldForColumn = false;
				// Add Search Column if different field passed
				if ($searchableField->getField() && false !== $columnData = $this->buildColumn(
						new Column(
							$searchableField->getField(),
							$column->getLabel(),
							searchable: $column->getSearchable(),
							sortable: false
						)
					)) {
					yield $columnData;
					$hasSearchableFieldForColumn = true;
				}

				if (false !== $columnData = $this->buildColumn(
						(clone $column)->setSearchable(
							$hasSearchableFieldForColumn ? false : $column->getSearchable()
						)->setSortable(false)
					)) {
					yield $columnData;
				}
			} else {
				if (false !== $columnData = $this->buildColumn($column)) {
					yield $columnData;
				}
			}
		}
	}

	/**
	 * @throws Exception
	 */
	protected function createQueryBuilder(
		Request $request,
		EntityColumnViewGroupEnum|string $viewGroup = EntityColumnViewGroupEnum::List,
	): QueryBuilder {
		$entity = $this->getEntity(true);
		$query = $this
			->getEntityRepository()
			->createQueryBuilder(self::ENTITY_ROOT_ALIAS);

		foreach (($entity->joins ?? []) as $join) {
			$query->{match ($join->type) {
				Join::LEFT_JOIN => 'leftJoin',
				default => 'innerJoin'
			}}(
				$join->fqcn,
				$join->alias,
				$join->conditionType ?? Join::WITH,
				$join->condition ?? null
			);
		}

		foreach ($entity->group ?? [] as $group) {
			$groupByField = (str_contains($group->field, '.') ? $group->field : sprintf(
				'%s.%s',
				self::ENTITY_ROOT_ALIAS,
				$group->field
			));

			$query->groupBy($groupByField);
		}

		$filters = $this->getFilterForm($request)?->getData();
		$sortingFields = array_filter($this->prepareSorting($request, $viewGroup));
		$action = $this->getAction($request);

		$this->buildCustomQuery($request, $action, $query);

		$usedJoinAliases = array_map(
			fn(Join $join) => $join->getAlias(),
			array_merge([], ...array_values($query->getDQLPart('join')))
		);

		/** @var PathParameterToFieldMap[] $mappedPathParameters */
		$mappedPathParameters = [
			...$this->getPHPAttributes(PathParameterToFieldMap::class),
			...($action ? $this->getPHPAttributes(PathParameterToFieldMap::class, $action->name) : []),
		];

		/** @var QueryParameterToFieldMap[] $mappedQueryParameters */
		$mappedQueryParameters = [
			...$this->getPHPAttributes(QueryParameterToFieldMap::class),
			...($action ? $this->getPHPAttributes(QueryParameterToFieldMap::class, $action->name) : []),
		];

		$allParameters = array_intersect_key(
			array_merge($request->query->all(), $request->attributes->all()),
			array_flip(
				array_filter(
					array_merge($request->query->keys(), $request->attributes->keys()),
					fn(string $key) => !str_starts_with($key, '_')
				)
			)
		);

		foreach (array_merge($mappedQueryParameters, $mappedPathParameters) as $mappedPathAttribute) {
			if (!isset($allParameters[$mappedPathAttribute->getParameter()])) {
				if (!$mappedPathAttribute->isRequired()) {
					continue;
				}

				throw new Exception(
					sprintf('Missing mapped attribute: %s', $mappedPathAttribute->getParameter())
				);
			}

			$column = $this->buildColumn(new Column($mappedPathAttribute->getField()));
			if (!$column) {
				throw new Exception(sprintf('Missing column for field: %s', $mappedPathAttribute->getField()));
			}

			$pathParameter = $mappedPathAttribute->getParameter();
			$pathParameterValue = $allParameters[$pathParameter];
			$queryParameterAlias = sprintf('pp%s', Container::camelize($mappedPathAttribute->getField()));
			$field = sprintf(
				'%s.%s',
				$column['entityAlias'],
				$column['entityField']
			);

			if ($pathParameterValue) {
				$query->andWhere(
					sprintf(
						'%s = :%s',
						$field,
						$queryParameterAlias
					)
				)->setParameter($queryParameterAlias, $pathParameterValue);
			} else {
				$query->andWhere(
					sprintf(
						'%s = \'\' OR %s is null',
						$field,
						$field
					)
				);
			}
		}

		$relationExpressions = [];
		foreach (
			$this->buildColumns($viewGroup, includeIdentifier: true) as ['entityAlias' => $entityAlias,
			'entityField' => $entityField,
			'relations' => $relations,
			'type' => $type,
			'column' => $column,
			'canSelect' => $canSelect,
			'nullable' => $nullable,]
		) {
			$queryEntityField = sprintf('%s.%s', $entityAlias, $entityField);
			$value = $filters[$column->getAlias()] ?? null;

			if ($value instanceof BackedEnum) {
				$value = $value->value;
			}

			if ($value instanceof Collection) {
				$value = $value->isEmpty() ? null : $value;
			}

			$isFilterApplied = $filters && null !== $value && false !== $column->getSearchable();

			if ($canSelect || $isFilterApplied) {
				foreach ($relations as $relation) {
					$relationExpression = $relation['entity'].'.'.$relation['field'];
					if (in_array($relationExpression, $relationExpressions)) {
						continue;
					}
					$relationExpressions[] = $relationExpression;

					if (in_array($relation['alias'], $usedJoinAliases)) {
						continue;
					}

					$query->leftJoin($relationExpression, $relation['alias']);
					$usedJoinAliases[] = $relation['alias'];
				}
			}

			if ($entityField === 'compositeId' && $this->getEntityClassMetadata()->isIdentifierComposite) {
				$query->addSelect(
					sprintf(
						'CONCAT(%s) as %s',
						implode(
							', \'-\', ',
							array_map(fn(string $field) => sprintf('IDENTITY(%s.%s)', $entityAlias, $field),
								$this->getEntityClassMetadata()->getIdentifier())
						),
						$column->getAlias()
					)
				);
			} else {
				if ($canSelect) {
					$query->addSelect(
						sprintf(
							'%s.%s as %s',
							$entityAlias,
							$entityField,
							$column->getAlias()
						)
					);
				}
			}

			if ($canSelect && $isFilterApplied) {
				$parameter = sprintf('p%s', $column->getAlias());

				switch ($type) {
					case Types::TEXT:
					case Types::STRING:
					case Types::SIMPLE_ARRAY:
					{
						$query
							->andWhere(
								sprintf(
									'%s LIKE :%s',
									$queryEntityField,
									$parameter
								)
							)
							->setParameter($parameter, sprintf('%%%s%%', addcslashes($value, '\\')));
						break;
					}
					case Types::DATE_MUTABLE:
					case Types::DATETIME_MUTABLE:
					{
						/** @var DateTime $value */
						if ($value instanceof DateTime) {
							$value = $value->format('Y-m-d');
						}
						$query->andWhere(
							sprintf(
								'DATE(%s) = \'%s\'',
								$queryEntityField,
								$value
							)
						);
						break;
					}
					case Types::BOOLEAN:
					default:
					{
						$query
							->andWhere(sprintf("%s IN (:%s)", $queryEntityField, $parameter))
							->setParameter($parameter, $value);
						break;
					}
				}
			}

			if (array_key_exists($column->getField(), $sortingFields)) {
				if ($nullable) {
					// Move NULL values at the end
					$query->addOrderBy(sprintf('CASE WHEN %s is null THEN 1 ELSE 0 END', $queryEntityField));
				}

				$query
					->addOrderBy($queryEntityField, $sortingFields[$column->getField()]);

				unset($sortingFields[$column->getField()]);
			}
		}

		// Add sort to non-searchable fields
		foreach ($sortingFields as $field => $direction) {
			if ($this->getEntityClassMetadata()->hasField($field)) {
				$queryEntityField = sprintf('%s.%s', self::ENTITY_ROOT_ALIAS, $field);
				if ($this->getEntityClassMetadata()->isNullable($field)) {
					// Move NULL values at the end
					$query->addOrderBy(sprintf('CASE WHEN %s is null THEN 1 ELSE 0 END', $queryEntityField));
				}

				$query
					->addOrderBy($queryEntityField, $direction);
			}
		}

		return $query;
	}

	public function getEntityIdentifierValueFromObject(mixed $object): string|null
	{
		try {
			return is_object($object) ? implode(
				self::COMPOSITE_IDENTIFIER_SEPARATOR,
				$this->serviceContainer->entityManager->getUnitOfWork()->getEntityIdentifier($object)
			) : null;
		} catch (EntityNotFoundException $e) {
			return null;
		}
	}

	public function getEntityIdentifierPrepare(mixed $id): array
	{
		return array_combine(
			$this->getEntityClassMetadata()->getIdentifier(),
			explode(self::COMPOSITE_IDENTIFIER_SEPARATOR, $id)
		);
	}

	public function getEntityPrimaryColumn(): Column
	{
		$identifiers = $this->getEntityClassMetadata()->getIdentifier();

		if (empty($identifiers)) {
			throw new Exception('No Primary Key found.');
		}

		if ($this->getEntityClassMetadata()->isIdentifierComposite) {
			return new Column('compositeId', group: false, searchable: false, identifier: true);
		} else {
			return new Column(array_shift($identifiers), group: false, searchable: false, identifier: true);
		}
	}

	/**
	 * @param EntityColumnViewGroupEnum|string|false|null $viewGroup
	 * @param bool $searchable
	 * @param bool $includeIdentifier
	 * @return Generator<int, Column>
	 * @throws Exception
	 */
	public function getEntityColumns(
		EntityColumnViewGroupEnum|string|false $viewGroup = null,
		bool|null $searchable = null,
		bool $includeIdentifier = false
	): Generator {
		$entity = $this->getEntity();
		if ($entity && empty($entity->columns)) {
			$entity->columns = array_map(
				fn(string $fieldName) => new Column($fieldName),
				$this->getEntityClassMetadata()->getFieldNames()
			);
		}

		$columns = array_filter(
			$entity?->columns ?: [],
			fn(Column $col) => (
					!$col->getGroup() ||
					in_array($viewGroup, $col->getGroup())
				)
				&&
				(
					$searchable === null || (
						null === $col->getSearchable() ||
						$col->getSearchable() instanceof SearchableOptions ||
						$searchable === $col->getSearchable()
					)
				)
				&&
				(
					$col->getRoles() === null || $this->serviceContainer->authorizationChecker->isGranted(
						is_array($col->getRoles()) ? new Expression(
							implode(
								' or ',
								array_map(fn(string $role) => sprintf('is_granted("%s")', $role), $col->getRoles())
							)
						) : $col->getRoles()
					)
				)
				&&
				(
					$col->getPermission() === null || $this->isAccessGranted($col->getPermission())
				)
		);

		if ($includeIdentifier) {
			$availableColumnFields = [];
			$primaryEntityColumn = $this->getEntityPrimaryColumn();
			foreach ($columns as $column) {
				$column->setIdentifier($column->getField() === $primaryEntityColumn->getField());
				$availableColumnFields[] = $column->getField();
			}

			if (!in_array($primaryEntityColumn->getField(), $availableColumnFields)) {
				$columns[] = $primaryEntityColumn;
			}
		}

		foreach ($columns as $column) {
			yield $column;
		}
	}

	public function getEntityClassMetadata(): ClassMetadata
	{
		if (!$this->entityClassMetadata) {
			$this->entityClassMetadata = $this->serviceContainer->entityManager->getClassMetadata(
				$this->getEntity(true)->getFqcn()
			);
		}

		return $this->entityClassMetadata;
	}

	/**
	 * @throws Exception
	 */
	public function getEntityShortName(): string
	{
		try {
			$classInstance = $this->getReflectionClass($this->getEntity(true)->getFqcn());
			$className = $classInstance->getShortName();
		} catch (ReflectionException) {
			throw new Exception(
				sprintf('Invalid or missing Entity FQCN (Class) definition: %s', $this->getEntity(true)->getFqcn())
			);
		}

		return $className;
	}

	public function getAlias(): string
	{
		$split = explode('\\', $this->getControllerClass());
		$controller_name = strtolower(str_replace('Controller', '', end($split)));

		return Container::underscore($controller_name);
	}

	protected function buildFormTypeOptions(Request $request, Action $action, array $options): array
	{
		return $options;
	}

	protected function onFormTypeCreate(Request $request, Action $action, FormInterface $type, object|null $object)
	{
	}

	protected function onFormTypeBeforeCreate(Request $request, $object, Action $action = null)
	{
	}

	protected function beforeFormSave(Request $request, FormInterface $form)
	{
	}

	protected function afterFormSave(Request $request, FormInterface $form)
	{
	}

	protected function findEntityObjectByRequest(Request $request, Action $action = null): false|object
	{
		return false;
	}

	protected function getEntityRepository(): ObjectRepository
	{
		return $this->serviceContainer->entityManager->getRepository($this->getEntity(true)->getFqcn());
	}

	protected function getRoute(Request $request, string $method = null): Route
	{
		if (null !== $action = current(
				array_values(
					array_filter($this->getActions($request), fn(Action $action) => $action->getName() === $method)
				)
			) ?: null) {
			return $action->getRoute();
		}

		if (!method_exists($this, $method)) {
			throw new NotFoundHttpException(sprintf('Missing Route "%s"', $method));
		}

		$routeName = $this->getControllerClass().'::'.$method;
		if (null === $route = $this->serviceContainer->router->getRouteCollection()->get($routeName)) {
			throw new NotFoundHttpException(sprintf('Route "%s" does not exist', $routeName));
		}

		return new Route($route->getPath(), $routeName, $route->getMethods());
	}

	public function getActions(Request $request, bool $onlyVisible = false): array
	{
		return array_values(
			array_filter(
				$this->actions ?: $this->actions = array_filter(
					iterator_to_array(
						$this->serviceContainer->actionCollection->load(
							$this->getControllerClass(),
							$this->getEntity()?->getFqcn()
						)
					),
					fn(Action $action) => null === $this->getEntity()?->getActions() || in_array(
							$action->getName(),
							$this->getEntity()?->getActions()
						)
				),
				fn(Action $action) => !$onlyVisible || ($this->isActionVisible(
							$request,
							$action
						) && $this->isActionAccessGranted($request, $action))
			)
		);
	}


	public function getAction(Request $request, string $name = null): ?Action
	{
		$name ??= $this->context?->method;

		return current(
			array_values(array_filter($this->getActions($request), fn(Action $action) => $action->getName() === $name))
		) ?: null;
	}

	/**
	 * Helper method to determine action visibility
	 *
	 * @param Request $request
	 * @param Action $action
	 * @return bool
	 */
	public function isActionVisible(Request $request, Action $action): bool
	{
		return true;
	}

	public function isActionAccessGranted(Request $request, Action $action, object|null $object = null): bool
	{
		return !$action->permission || $this->isAccessGranted($action->permission, $object);
	}

	public function isAccessGranted(string $permission, object|null $object = null): bool
	{
		$entity = $this->getEntity();
		if ($object && $entity?->getFqcn() !== $objectFCQN = $this->serviceContainer->entityManager->getClassMetadata($object::class)->getName()) {
			$entity = new Entity($objectFCQN);
		}

		return $this->serviceContainer->authorizationChecker->isGranted(
			$permission,
			new SecuritySubject($entity, $object)
		);
	}

	protected function getExpressionLanguage(): ExpressionLanguage
	{
		if (!$this->expressionLanguage) {
			$this->expressionLanguage = new ExpressionLanguage;
		}

		return $this->expressionLanguage;
	}

	private function getReflectionClass(string $class): ReflectionClass
	{
		return $this->reflectionCache[$class] ??= new ReflectionClass($class);
	}
}

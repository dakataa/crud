<?php

namespace Dakataa\Crud\Controller;

use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\EntityGroup;
use Dakataa\Crud\Attribute\EntityJoinColumn;
use Dakataa\Crud\Attribute\EntityType;
use Dakataa\Crud\Attribute\SearchableOptions;
use Dakataa\Crud\Enum\SortTypeEnum;
use Dakataa\Crud\Utils\Doctrine\Paginator;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Html;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Closure;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use TypeError;

abstract class AbstractCrudController extends AbstractController implements CrudControllerInterface
{
	const ENTITY_ROOT_ALIAS = 'a';

	const MODE_DISPLAY = 'display';
	const MODE_EXPORT = 'export';

	const EXPORT_EXCEL = 'excel';
	const EXPORT_EXCEL2007 = 'excel2007';
	const EXPORT_CSV = 'csv';
	const EXPORT_HTML = 'html';

	const DEFAULT_RESULTS_LIMIT = 5;

	protected ?Entity $entity = null;
	protected ?EntityType $entityType = null;

	private function getAttributes(string $attributeClass): array
	{
		return array_map(fn(ReflectionAttribute $attribute) => $attribute->newInstance(),
			(new ReflectionClass($this))->getAttributes($attributeClass));
	}

	private function getAttribute(string $attributeClass): mixed
	{
		return ($this->getAttributes($attributeClass)[0] ?? null);
	}

	public function __construct(
		protected FormFactoryInterface $formFactory,
		protected RouterInterface $router,
		protected EventDispatcherInterface $dispatcher,
		protected EntityManagerInterface $entityManager,
		protected ParameterBagInterface $parameterBag,
		protected Environment $twig
	) {
		$this->entity = $this->getAttribute(Entity::class);
		$this->entityType = $this->getAttribute(EntityType::class);

		if (empty($this->entity?->joins)) {
			$this->entity?->setJoins($this->getAttributes(EntityJoinColumn::class));
		}

		if (empty($this->entity?->group)) {
			$this->entity?->setGroup($this->getAttributes(EntityGroup::class));
		}

		if (empty($this->entity?->columns)) {
			$this->entity?->setColumns($this->getAttributes(Column::class));
		}
	}

	public function getEntity(): Entity
	{
		if (!$this->entity) {
			throw new Exception('Invalid Entity. Add PHP Attribute or extend getEntity method.');
		}

		return $this->entity;
	}

	public function getEntityType(): ?EntityType
	{
		return $this->entityType;
	}

	public final function redirectToRoute(
		string $route,
		array $parameters = [],
		int $status = 302
	): RedirectResponse {
		return new RedirectResponse($this->router->generate($route, $parameters, $status));
	}

	protected function prepareData(Paginator $paginator, string $mode = null)
	{
		$compileEntityData = function (array|object $entity) use ($mode) {
			$additionalEntityFields = [];

			if (is_array($entity)) {
				if ($entity[0]::class === $this->getEntity()->getFqcn()) {
					$additionalEntityFields = array_filter(
						$entity,
						fn(mixed $key) => !is_numeric($key),
						ARRAY_FILTER_USE_KEY
					);
					$entity = $entity[0];
				} else {
					throw new Exception('Invalid results.');
				}
			}

			foreach ($this->getEntityColumns($mode) as $column) {
				$field = $column->getAlias();

				$value = null;
				foreach (['get', 'has', 'is'] as $methodPrefix) {
					$method = sprintf('%s%s', $methodPrefix, Container::underscore($field));
					$method = Container::camelize($method);

					if (method_exists($entity, $method)) {
						$value = $entity->$method();
						break;
					}
				}

				if ($getter = $column->getGetter()) {
					if (is_string($getter)) {
						$getter = sprintf('get%s', (preg_replace('/^get/i', '', Container::camelize($getter))));

						if (method_exists($entity, $getter)) {
							$value = $entity->$getter();
						}
					}

					if (is_callable($getter) && $getter instanceof Closure) {
						$value = $getter($value);
					}

					if ($value instanceof Collection) {
						$value = implode(', ', $value->getValues());
					}
				}

				if (null === $value && isset($additionalEntityFields[$field])) {
					$value = $additionalEntityFields[$field];
				}

				if ($value instanceof Stringable) {
					$value = $value->__toString();
				} else {
					if ($value instanceof DateTime) {
						$value = $value->format($column->getOption('dateFormat') ?: DateTimeInterface::ATOM);
					}
				}

				try {
					$enum = $column->getEnum() ?: [];
					$column->setValue($enum[$value] ?? $value);
				} catch (TypeError $e) {
				}

				yield $column;
			}
		};


		['items' => $items, 'meta' => $meta] = $paginator->paginate();

		return [
			'items' => array_map(fn(array|object $entity) => [
				'entity' => is_object($entity) ? $entity : $entity[0],
				'data' => $compileEntityData($entity),
			], iterator_to_array($items)),
			'meta' => $meta,
		];
	}

	/**
	 * @throws Exception
	 */
	#[Route]
	public function list(Request $request): Response
	{
		//Sort list by column
		$sorting = $this->prepareSorting($request);
		$query = $this
			->getEntityRepository()
			->createQueryBuilder(self::ENTITY_ROOT_ALIAS);

		$this
			->buildQuery($request, $query)
			->buildCustomQuery($request, $query);

		$dataProvider = (new Paginator($query, $request->query->getInt('page', 1)))
			->setMaxResults($this->prepareResultsLimit($request));

		return $this->response('list', [
			'dataProvider' => $this->prepareData($dataProvider),
			'filterForm' => $this->getFilterForm($request)->submit($this->getFilters($request))->createView(),
			'batchForm' => $this->getBatchForm($request)->createView(),
			'columns' => array_filter(
				$this->getEntityColumns(),
				fn(Column $column) => $column->getSearchable() !== false
			),
			'sort' => $sorting,
		]);
	}

	/**
	 * @throws Exception
	 */
	#[Route(path: '/export/{type}')]
	public function export(
		Request $request,
		TranslatorInterface $translator,
		string $type = self::EXPORT_EXCEL
	): StreamedResponse {
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
		$query = $this->getEntityRepository()->createQueryBuilder('a');
		$this
			->buildQuery($request, $query, self::MODE_EXPORT)
			->buildCustomQuery($request, $query);

		$objects = $query->getQuery()->getResult();

		$columns = $this->getExportFields();

		//Excel
		$spreadsheet = new Spreadsheet();
		$spreadsheet
			->getProperties()
			->setCreator(sprintf('Auto generated: %s', $this->getUser()))
			->setTitle('Export')
			->setCompany('Company');

		//Header
		$header = [];
		foreach ($columns as $columnOption) {
			$header[] = $translator->trans($columnOption->getLabel());
		}
		$rows = [$header];

		//Rows
		foreach ($objects as $object) {
			$customObjectFields = [];

			if (is_array($object)) {
				if ($object[0]::class === $this->getEntity()->getFqcn()) {
					$customObjectFields = array_filter($object, fn($key) => !is_numeric($key), ARRAY_FILTER_USE_KEY);
					$object = $object[0];
				} else {
					throw new Exception('Invalid results.');
				}
			}

			$row = [];
			foreach ($columns as $columnField => $columnOption) {
				$field = $columnOption->field ?? $columnField;
				$value = null;
				foreach (['get', 'has', 'is'] as $methodPrefix) {
					$method = sprintf('%s%s', $methodPrefix, Container::underscore($field));
					$method = Container::camelize($method);

					if (method_exists($object, $method)) {
						$value = $object->$method();
						break;
					}
				}

				if (null === $value) {
					if (isset($customObjectFields[$columnField])) {
						$value = $customObjectFields[$columnField];
					}
				}

				$enum = $columnOption->enum ?: [];
				$emptyValue = $columnOption->placeholder ?: null;

				if (is_object($value)) {
					$getter = $columnOption['objectGetter'] ?? null;

					if (!empty($getter)) {
						$getter = sprintf('get%s', (preg_replace('/^get/i', '', Container::camelize($getter))));

						if (method_exists($value, $getter)) {
							$value = $value->$getter();
						}
					}
				}

				if ($value instanceof DateTime) {
					$value = $value->format($columnOption['dateFormat'] ?? DateTimeInterface::ISO8601);
				}

				array_push($row, $enum[$value] ?? $value ?? $emptyValue);
			}
			array_push($rows, $row);
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
		$response = new StreamedResponse(fn() => $writer->save('php://output'));
		$response->headers->set('Content-Type', 'application/force-download');
		$response->headers->set('Content-Disposition', 'attachment; filename="export.'.$exportType['ext'].'"');

		return $response;
	}

	/**
	 * @throws Exception
	 */
	#[Route(path: '/add')]
	public function add(Request $request): ?Response
	{
		return $this->edit($request);
	}

	/**
	 * @throws Exception
	 */
	protected function response(string $action, array $parameters = []): Response
	{
		$controllerPatterns = '#Controller\\\(?<class>.+)Controller$#';
		preg_match($controllerPatterns, static::class, $matches);

		if (empty($matches['class'])) {
			throw new Exception('Invalid Controller Class.');
		}

		$path = rtrim(
			Container::underscore(str_replace('\\', '/', preg_replace('/Action$/i', '', $matches['class']))),
			'/'
		);

		$templatePath = sprintf('%s/%s.html.twig', $path, $action);

		if (!$this->twig->getLoader()->exists($templatePath)) {
			$templatePath = sprintf('@DakataaCrud/%s.html.twig', $action);
		}

		return $this->render($templatePath, $parameters);
	}

	/**
	 *
	 * @param int|null $id
	 * @throws Exception
	 */
	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'])]
	public function edit(Request $request, int $id = null): ?Response
	{
		if (empty($this->getEntityType())) {
			throw new NotFoundHttpException('Not Entity Type found.');
		}

		$object = null;
		if ($id) {
			$metadata = $this->entityManager->getClassMetadata($this->getEntity()->getFqcn());

			if (count($metadata->getIdentifierFieldNames()) > 1) {
				throw new Exception('Entity with two or more identifier columns are not supported.');
			}

			$identifierColumn = $metadata->getSingleIdentifierFieldName();

			$queryBuilder = $this
				->getEntityRepository()
				->createQueryBuilder(self::ENTITY_ROOT_ALIAS)
				->where(sprintf('%s.%s = :id', self::ENTITY_ROOT_ALIAS, $identifierColumn))
				->setParameter('id', $id);

			$object = $queryBuilder->getQuery()->getOneOrNullResult();

			if (empty($object)) {
				throw new NotFoundHttpException('Not Found');
			}
		}

		//Setup Form type options
		$formOptions = [
			'action' => $request->getUri(),
			'method' => 'POST',
			'attr' => [
				'data-submit' => 'true',
			],
		];
		$formOptions = array_merge_recursive($formOptions, $this->getEntityType()->getOptions());
		//PERMISSION
		if (empty($object)) {
			$entityClass = $this->getEntity()->getFqcn();
			$object = new $entityClass();
		}

		$this->onFormTypeBeforeCreate($request, $object);
		$form = $this->formFactory->createNamed(
			'form_'.Container::underscore($this->getEntityShortName()).'_'.($id ?? 'new'),
			$this->getEntityType()->getFqcn(),
			$object,
			$formOptions
		);
		$this->onFormTypeCreate($request, $form, $object);
		if ($request->isMethod(Request::METHOD_POST)) {
			$form->handleRequest($request);

			if ($form->isSubmitted() && $form->isValid()) {
				$this->beforeFormSave($request, $form);

				$this->entityManager->persist($form->getData());
				$this->entityManager->flush();
				$this->afterFormSave($request, $form);
				$request->getSession()->getFlashBag()->add('notice', 'Item was saved successfully.');

				$object = $form->getData();

				return new RedirectResponse(
					$this->router->generate(
						$this->getRoute('edit'),
						['id' => method_exists($object, 'getId') ? $object->getId() : null]
					)
				);
			}
		}

		return $this->response('edit', [
			'object' => $object,
			'form' => $form->createView(),
		]);
	}

	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'])]
	public function delete(Request $request, int $id): Response
	{
		$object = $this->getEntityRepository()->find($id);
		if ($object) {
			$this->batchDelete($request, [$object]);
		}

		return new RedirectResponse($this->router->generate($this->getRoute('list'), $request->query->all()));
	}


	/**
	 * @throws Exception
	 */
	#[Route(path: '/batch')]
	public function batch(Request $request): Response
	{
		$form = $this->getBatchForm($request);
		if ($request->isMethod(Request::METHOD_POST)) {
			$form->handleRequest($request);
			if ($form->isSubmitted() && $form->isValid()) {
				$method = Container::camelize('batch_'.Container::underscore($form->get('method')->getData()));

				if (!method_exists($this, $method)) {
					throw new Exception(sprintf('Method %s not exists', $method));
				}

				$objects = $this->getEntityRepository()->findBy(['id' => $form->get('ids')->getData()]);
				if ($response = $this->$method($request, $objects)) {
					if ($response instanceof Response) {
						return $response;
					}
				}
			}
		}

		return new RedirectResponse($this->router->generate($this->getRoute('list'), $request->query->all()));
	}

	protected function batchDelete(Request $request, array $objects): void
	{
		if (count($objects)) {
			foreach ($objects as $object) {
				$this->entityManager->remove($object);
			}

			$this->entityManager->flush();
			$request->getSession()->getFlashBag()->add('notice', 'Items was deleted successfully!');
		}
	}

	protected function getBatchForm(Request $request): FormInterface
	{
		$form = $this
			->formFactory
			->createNamedBuilder('batch', options: [
				...($this->parameterBag->get('form.type_extension.csrf.enabled') ? ['csrf_protection' => false] : []),
			])
			->setAction($this->router->generate($this->getRoute('batch')))
			->setMethod('POST');

		$form
			->add('ids', CollectionType::class, [
				'required' => true,
				'allow_add' => true,
				'constraints' => [
					new Assert\NotBlank(),
				],
			]);


		$classInstance = new ReflectionClass(static::class);
		$methods = [];
		foreach (
			$classInstance->getMethods(
				ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
			) as $reflectionMethod
		) {
			if (!str_starts_with($reflectionMethod->getShortName(), 'batch')) {
				continue;
			}

			$action = Container::underscore(str_replace('batch', '', $reflectionMethod->getShortName()));
			if (empty($action)) {
				continue;
			}

			$methods[] = [
				'action' => $action,
				'label' => $action,
			];
		}

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
	#[Route(path: '/filter')]
	public function filter(Request $request): Response
	{
		$filterData = $request->get('filter', []);
		$form = $this
			->getFilterForm($request)
			->submit($filterData);

		$this->setFilters($request, $form->isValid() ? $filterData : []);

		return new RedirectResponse($this->router->generate($this->getRoute('list')));
	}

	/**
	 * @throws Exception
	 */
	protected function getFilterForm(Request $request): FormInterface
	{
		$form = $this->formFactory->createNamedBuilder(
			'filter',
			FormType::class,
			null,
			[
				...($this->parameterBag->get('form.type_extension.csrf.enabled') ? ['csrf_protection' => false] : []),
			]
		);
		$form
			->setAction($this->router->generate($this->getRoute('filter')))
			->setMethod(Request::METHOD_GET);

		foreach ($this->buildColumns() as $columnData) {
			[
				'fqcn' => $fqcn,
				'type' => $type,
				'column' => $column,
			] = $columnData;

			if ($column->getSearchable() === false) {
				continue;
			}

			$formFieldKey = $column->getAlias();
			$columnOptions = [
				'label' => $column->getLabel(),
			];

			$entityType = $type ?? TextType::class;

			if ($column->getSearchable() instanceof SearchableOptions) {
				$columnOptions = [
					...($column->getSearchable()->getOptions() ?: []),
					...$columnOptions,
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
					$form->add($formFieldKey, TextType::class, $columnOptions);
					break;
				case Types::DATE_MUTABLE:
				case Types::DATETIME_MUTABLE:
					$form->add($formFieldKey, DateType::class, array_merge(['placeholder' => ''], $columnOptions));
					break;
				case Types::BOOLEAN:
					$form->add($formFieldKey, CheckboxType::class, $columnOptions);
					break;
				default:
					if (empty($entityType) || !is_string($entityType)) {
						$entityType = null;
					}

					$form->add(
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

		return $form->getForm();
	}

	protected function getDefaultSort(): ?array
	{
		return null;
	}

	protected function prepareSorting(Request $request = null, bool $update = true): array
	{
		if ($request->query->has('sort')) {
			$sorting = $request->query->all('sort', []);
		} else {
			$sorting = $request->getSession()->get($this->getAlias().'.sort', $this->getDefaultSort() ?: []);
		}

		$buildedColumns = array_reduce(
			array_filter(iterator_to_array($this->buildColumns()), fn(array $c) => $c['column']->getSortable() !== false),
			fn(array $c, array $item) => [...$c, $item['column']->getField() => $item],
			[]
		);
		$sorting = array_intersect_key($sorting, $buildedColumns) + array_fill_keys(array_keys($buildedColumns), null);

		$request->getSession()->set($this->getAlias().'.sort', $sorting);

		return array_reduce(
			array_keys($sorting),
			fn(array $c, string $field) => [...$c, $buildedColumns[$field]['column']->getAlias() => $sorting[$field]],
			[]
		);
	}

	public function prepareResultsLimit(Request $request): int
	{
		if ($request->query->has('limit')) {
			$limit = round(
					($request->query->getInt('limit', self::DEFAULT_RESULTS_LIMIT)) / self::DEFAULT_RESULTS_LIMIT
				) * self::DEFAULT_RESULTS_LIMIT;

			$request->getSession()->set($this->getAlias().'.limit', min(100, max(self::DEFAULT_RESULTS_LIMIT, $limit)));
		} else {
			$limit = min(
				100,
				max(
					self::DEFAULT_RESULTS_LIMIT,
					intval($request->getSession()->get($this->getAlias().'.limit', self::DEFAULT_RESULTS_LIMIT))
				)
			);
		}

		return $limit;
	}

	protected function getFilters(Request $request): array
	{
		return $request->getSession()->get($this->getAlias().'.filters', []);
	}

	protected function setFilters(Request $request, array $filters): self
	{
		$request->getSession()->set($this->getAlias().'.filters', $filters);

		return $this;
	}

	protected function getFilterValue(Request $request, string $key): mixed
	{
		return $this->getFilters($request)[$key] ?? null;
	}


	protected function buildCustomQuery(Request $request, QueryBuilder $query): self
	{
		return $this;
	}

	private function buildColumns(string $mode = null): \Generator
	{

		$rootEntityMetadata = $this->entityManager->getClassMetadata($this->getEntity()->getFqcn());
		$buildColumn = function (string $fieldName, Column $column) use ($rootEntityMetadata): array|false {
			$entityMetadata = $rootEntityMetadata;
			$entityAlias = self::ENTITY_ROOT_ALIAS;
			$assotiations = [];

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
					$assotiations[] = [
						'entity' => lcfirst($rootAlias ?: self::ENTITY_ROOT_ALIAS),
						'field' => $entityRelation,
						'alias' => lcfirst($entityAlias),
					];

					$entityMetadata = $this->entityManager->getClassMetadata($associationMapping['targetEntity']);
				}
			} else {
				if (!$entityMetadata->hasField($fieldName)) {
					return false;
				}
			}

			return [
				'fqcn' => $entityMetadata->getReflectionClass()->name,
				'entityAlias' => lcfirst($entityAlias),
				'entityField' => $fieldName,
				'assotiations' => $assotiations,
				'type' => $entityMetadata->getTypeOfField($fieldName),
				'column' => $column,
				'canSelect' => $entityMetadata->hasField($fieldName) && false === $entityMetadata->hasAssociation($fieldName)
			];
		};

		foreach ($this->getEntityColumns($mode) as $column) {
			if(false !== $columnData = $buildColumn($column->getField(), $column)) {
				yield $columnData;
			}

			if((($searchableField = $column->getSearchable()) instanceof SearchableOptions) ) {
				if($searchableField->getField() && false !== $columnData = $buildColumn($searchableField->getField(), new Column($searchableField->getField(), searchable: $searchableField, sortable: false))) {
					yield $columnData;
				}
			}
		}
	}

	/**
	 * @throws Exception
	 */
	protected function buildQuery(Request $request, QueryBuilder $query, string $mode = self::MODE_DISPLAY): self
	{
		$entity = $this->getEntity();
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

		if ($entity->group) {
			foreach ($entity->group ?? [] as $group) {
				$groupByField = (str_contains($group->field, '.') ? $group->field : sprintf(
					'%s.%s',
					self::ENTITY_ROOT_ALIAS,
					$group->field
				));

				$query->groupBy($groupByField);
			}
		}

		$filters = array_filter($this->getFilters($request), fn(mixed $value) => ($value !== null && $value !== ''));

		foreach ($this->buildColumns($mode) as $columnData) {
			[
				'entityAlias' => $entityAlias,
				'entityField' => $entityField,
				'assotiations' => $assotiations,
				'type' => $type,
				'column' => $column,
				'canSelect' => $canSelect
			] = $columnData;

			$hasFilterApplied = isset($filters[$column->getAlias()]) && false !== $column->getSearchable();

			if($canSelect || $hasFilterApplied) {
				foreach ($assotiations as $assotiation) {
					$query->innerJoin($assotiation['entity'].'.'.$assotiation['field'], $assotiation['alias']);
				}
			}

			if($canSelect) {
				$query->addSelect(
					sprintf(
						'%s.%s as %s',
						$entityAlias,
						$entityField,
						$column->getAlias()
					)
				);
			}

			if ($hasFilterApplied) {
				$value = $filters[$column->getAlias()];
				$type = ($column->getSearchable() instanceof SearchableOptions ? $column->getSearchable()->getType(
				) : null) ?? $type ?? Types::STRING;
				$parameter = sprintf('p%s', $column->getAlias());

				switch ($type) {
					case Types::TEXT:
					case Types::STRING:
					case Types::SIMPLE_ARRAY:
					{
						$query
							->andWhere(
								sprintf(
									'%s.%s LIKE :%s',
									$entityAlias,
									$entityField,
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
								$filterField,
								$value
							)
						);
						break;
					}
					case Types::BOOLEAN:
					default:
					{
						$query
							->andWhere(sprintf("%s.%s IN (:%s)", $entityAlias, $entityField, $parameter))
							->setParameter($parameter, $value);
						break;
					}
				}
			}
		}

		foreach (array_filter($this->prepareSorting($request, false)) as $sortField => $value) {
			$query->addOrderBy($sortField, $value);
		}

		return $this;
	}

	/**
	 * @param string|null $mode
	 * @return Column[]
	 * @throws Exception
	 *
	 */
	public function getEntityColumns(string $mode = null, bool $all = false): array
	{
		$fields = $this->entity->columns;

		if (empty($fields) || $all) {
			foreach (
				$this->entityManager->getClassMetadata($this->getEntity()->getFqcn())->getFieldNames() as $fieldName
			) {
				if ($entityColumn = $this->getEntityFieldMetadata($fieldName)) {
					$fields[] = new Column($fieldName);
				}
			}
		}

		return $fields;
	}

	/**
	 * @param $field
	 * @throws Exception
	 */
	public function getEntityFieldMetadata(string $field): ?array
	{
		$entityClassMetaData = $this->entityManager->getClassMetadata($this->getEntity()->getFqcn());

		if (!$entityClassMetaData->hasField(lcfirst($field))) {
			return null;
		}

		return $entityClassMetaData->getFieldMapping(lcfirst($field));
	}

	protected function getExportFields(): array
	{
		return [];
	}

	/**
	 * @throws Exception
	 */
	public function getEntityShortName(): string
	{
		try {
			$classInstance = new ReflectionClass($this->getEntity()->getFqcn());
			$className = $classInstance->getShortName();
		} catch (ReflectionException) {
			throw new Exception(
				sprintf('Invalid or missing Entity FQCN (Class) definition: %s', $this->getEntity()->getFqcn())
			);
		}

		return $className;
	}

	public function getAlias(): string
	{
		$controller_class = static::class;
		$split = explode('\\', $controller_class);
		$controller_name = strtolower(str_replace('Controller', '', end($split)));

		return Container::underscore($controller_name);
	}

	protected function onFormTypeCreate(Request $request, FormInterface &$type, &$object)
	{
	}

	protected function onFormTypeBeforeCreate(Request $request, &$object)
	{
	}

	protected function beforeFormSave(Request $request, FormInterface $form)
	{
	}

	protected function afterFormSave(Request $request, FormInterface $form)
	{
	}

	protected function getEntityRepository(): ObjectRepository
	{
		return $this->entityManager->getRepository($this->getEntity()->getFqcn());
	}

	protected function getRoute(string $method = null): string
	{
		return $this::class.'::'.$method;
	}
}

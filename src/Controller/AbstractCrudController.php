<?php

namespace Dakataa\Crud\Controller;

use Closure;
use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\EntityGroup;
use Dakataa\Crud\Attribute\EntityJoinColumn;
use Dakataa\Crud\Attribute\EntityType;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewTypeEnum;
use Dakataa\Crud\Attribute\SearchableOptions;
use Dakataa\Crud\Serializer\Normalizer\ColumnNormalizer;
use Dakataa\Crud\Serializer\Normalizer\FormErrorNormalizer;
use Dakataa\Crud\Serializer\Normalizer\FormViewNormalizer;
use Dakataa\Crud\Utils\Doctrine\Paginator;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectRepository;
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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Twig\Environment;
use TypeError;

abstract class AbstractCrudController implements CrudControllerInterface
{
	const ENTITY_ROOT_ALIAS = 'a';

	const EXPORT_EXCEL = 'excel';
	const EXPORT_EXCEL2007 = 'excel2007';
	const EXPORT_CSV = 'csv';
	const EXPORT_HTML = 'html';

	const DEFAULT_RESULTS_LIMIT = 5;

	protected ?Entity $entity = null;
	protected ?EntityType $entityType = null;
	protected ?ClassMetadata $entityClassMetadata = null;

	protected function getAttributes(string $attributeClass): array
	{
		return array_map(fn(ReflectionAttribute $attribute) => $attribute->newInstance(),
			(new ReflectionClass($this))->getAttributes($attributeClass));
	}

	protected function getAttribute(string $attributeClass): mixed
	{
		return ($this->getAttributes($attributeClass)[0] ?? null);
	}

	public function __construct(
		protected FormFactoryInterface $formFactory,
		protected RouterInterface $router,
		protected EventDispatcherInterface $dispatcher,
		protected EntityManagerInterface $entityManager,
		protected ParameterBagInterface $parameterBag,
		protected ?Environment $twig = null,
		protected ?SerializerInterface $serializer = null
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

		if (count($this->getEntityClassMetadata()->getIdentifierFieldNames()) > 1) {
			throw new Exception('Entity with two or more identifier columns are not supported.');
		}
	}

	public function getEntity(): Entity
	{
		if (!$this->entity) {
			throw new Exception(
				'Invalid CRUD Entity Class. Add PHP Attribute "Dakataa\Crud\Attribute\Entity" or extend getEntity method.'
			);
		}

		return $this->entity;
	}

	public function getEntityType(): ?EntityType
	{
		return $this->entityType;
	}

	protected function compileEntityData(
		array|object $object,
		EntityColumnViewTypeEnum $viewType = null,
		bool $raw = false
	): array {
		$additionalEntityFields = [];
		if (is_array($object)) {
			if ($object[0]::class === $this->getEntity()->getFqcn()) {
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

		$result = [];
		foreach ($this->getEntityColumns($viewType) as $column) {
			$field = $column->getAlias();

			$value = null;
			foreach (['get', 'has', 'is'] as $methodPrefix) {
				$method = Container::camelize(sprintf('%s%s', $methodPrefix, Container::underscore($field)));

				if (method_exists($object, $method)) {
					$value = $object->$method();
					break;
				}
			}

			if ($getter = $column->getGetter()) {
				if (is_string($getter)) {
					$getter = sprintf('get%s', (preg_replace('/^get/i', '', Container::camelize($getter))));

					if (method_exists($object, $getter)) {
						$value = $object->$getter();
					}
				}

				if (is_callable($getter) && $getter instanceof Closure) {
					$value = $getter($value);
				}
			}


			if (null === $value && isset($additionalEntityFields[$field])) {
				$value = $additionalEntityFields[$field];
			}

			if ($value instanceof Collection) {
				$value = implode(', ', $value->getValues());
			}

			if ($value instanceof DateTime) {
				$value = $value->format($column->getOption('dateFormat') ?: DateTimeInterface::ATOM);
			}

			if ($value instanceof Stringable) {
				$value = $value->__toString();
			}

			if (is_object($value)) {
				$value = json_encode($value);
			}

			try {
				$enum = $column->getEnum() ?: [];
				$value = $enum[$value] ?? $value;
			} catch (TypeError $e) {
			}

			$result[$column->getField()] = $value;
		}

		return $result;
	}

	protected function prepareListData(Paginator $paginator, EntityColumnViewTypeEnum $viewType = null): array
	{
		['items' => $items, 'meta' => $meta] = $paginator->paginate();

		return [
			'items' => array_map(fn(array|object $object) => [
				'object' => is_object($object) ? $object : $object[0],
				'data' => $this->compileEntityData($object, $viewType),
			], iterator_to_array($items)),
			'meta' => $meta,
		];
	}

	/**
	 * @throws Exception
	 */
	#[
		Route,
		Action
	]
	public function list(Request $request, Action $action = null): Response
	{
		$batchForm = $this->handleBatch($request);
		if ($batchForm instanceof Response) {
			return $batchForm;
		}

		$filterData = $request->get('filter', $this->getFilters($request));
		$filterForm = $this
			->getFilterForm($request)
			->submit($filterData);

		$this->setFilters($request, $filterForm->isValid() ? $filterData : []);

		$sorting = $this->prepareSorting($request);
		$query = $this
			->getEntityRepository()
			->createQueryBuilder(self::ENTITY_ROOT_ALIAS);

		$this
			->buildQuery($request, $query)
			->buildCustomQuery($request, $query);

		$paginator = (new Paginator($query, $request->query->getInt('page', 1)))
			->setMaxResults($this->prepareResultsLimit($request));

		$request->attributes->set('_entityFQCN', $this->entity->fqcn);

		return $this->response($request, [
			'columns' => iterator_to_array($this->getEntityColumns(searchable: false)),
			'data' => $this->prepareListData($paginator),
			'filterForm' => $filterForm->createView(),
			'batchForm' => $this->getBatchForm($request)->createView(),
			'sort' => $sorting,
			'title' => $action?->title,
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
		string $type = self::EXPORT_EXCEL,
		Action $action = null
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
			->buildQuery($request, $query, EntityColumnViewTypeEnum::Export)
			->buildCustomQuery($request, $query);

		$objects = $query->getQuery()->getResult();


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
		foreach ($this->buildColumns(EntityColumnViewTypeEnum::Export) as ['column' => $column]) {
			$header[] = $column->getLabel();
		}
		$rows = [$header];

		//Rows
		foreach ($objects as $object) {
			$rows[] = $this->compileEntityData($object, EntityColumnViewTypeEnum::Export);
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
	#[Action]
	public function add(Request $request): ?Response
	{
		return $this->edit($request);
	}

	/**
	 * @throws Exception
	 */
	#[Route(path: '/{id}/view', requirements: ['id' => '\d+'])]
	#[Action]
	public function view(Request $request, int $id, Action $action = null): ?Response
	{
		$queryBuilder = $this
			->getEntityRepository()
			->createQueryBuilder(self::ENTITY_ROOT_ALIAS)
			->where(
				sprintf(
					'%s.%s = :id',
					self::ENTITY_ROOT_ALIAS,
					$this->getEntityClassMetadata()->getSingleIdentifierFieldName()
				)
			)
			->setParameter('id', $id);

		$object = $queryBuilder->getQuery()->getOneOrNullResult();
		if (empty($object)) {
			throw new NotFoundHttpException('Not Found');
		}

		return $this->response($request, [
			'object' => $object,
			'data' => $this->compileEntityData($object),
			'columns' => $this->getEntityColumns(EntityColumnViewTypeEnum::View)
		], defaultTemplate: 'view');
	}

	/**
	 * @throws Exception
	 */
	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'])]
	#[Action]
	public function edit(Request $request, int $id = null, Action $action = null): ?Response
	{
		if (empty($this->getEntityType())) {
			throw new NotFoundHttpException('Not Entity Type found.');
		}

		$object = null;
		$entityClassIdentifierFieldName = $this->getEntityClassMetadata()->getSingleIdentifierFieldName();
		if ($id) {
			$queryBuilder = $this
				->getEntityRepository()
				->createQueryBuilder(self::ENTITY_ROOT_ALIAS)
				->where(sprintf('%s.%s = :id', self::ENTITY_ROOT_ALIAS, $entityClassIdentifierFieldName))
				->setParameter('id', $id);

			$object = $queryBuilder->getQuery()->getOneOrNullResult();
			if (empty($object)) {
				throw new NotFoundHttpException('Not Found');
			}
		}

		//Setup Form type options
		$formOptions = array_merge_recursive([
			'action' => $request->getUri(),
			'method' => Request::METHOD_POST,
			'csrf_protection' => false
		], $this->getEntityType()->getOptions() ?: []);

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
		$responseStatus = 200;
		if ($request->isMethod(Request::METHOD_POST)) {
			$form->handleRequest($request);

			if ($form->isSubmitted() && $form->isValid()) {
				$this->beforeFormSave($request, $form);

				$this->entityManager->persist($form->getData());
				$this->entityManager->flush();

				$this->afterFormSave($request, $form);

				if($request->hasSession()) {
					$request->getSession()->getFlashBag()->add('notice', 'Item was saved successfully.');
				}

				if($request->getPreferredFormat() === 'html') {
					return new RedirectResponse($this->router->generate($this->getRoute('edit'), [
						'id' => $this->getEntityClassMetadata()->getIdentifierValues($form->getData())[$entityClassIdentifierFieldName] ?? null
					]));
				}
			} else {
				$responseStatus = 400;
			}
		}

		return $this->response($request, [
			'object' => $object,
			'form' => $form->createView(),
		], $responseStatus, defaultTemplate: 'edit');
	}

	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'])]
	#[Action]
	public function delete(Request $request, int $id): Response
	{
		$object = $this->getEntityRepository()->find($id);
		if ($object) {
			$this->batchDelete($request, [$object]);
		}

		return new RedirectResponse($this->router->generate($this->getRoute('list'), $request->query->all()));
	}

	protected function handleBatch(Request $request): Response|Form
	{
		$form = $this->getBatchForm($request);
		if ($request->isMethod(Request::METHOD_POST)) {
			$form->handleRequest($request);
			if ($form->isSubmitted() && $form->isValid()) {
				$method = Container::camelize('batch_'.Container::underscore($form->get('method')->getData()));

				if (!method_exists($this, $method)) {
					throw new Exception(sprintf('Method %s not exists', $method));
				}

				$objects = $this->getEntityRepository()->findBy(
					[$this->getEntityClassMetadata()->getSingleIdentifierFieldName() => $form->get('ids')->getData()]
				);
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
				$this->entityManager->remove($object);
			}

			$this->entityManager->flush();
			if($request->hasSession()) {
				$request->getSession()->getFlashBag()->add('notice', 'Items was deleted successfully!');
			}
		}
	}

	protected function getControllerClass(): string
	{
		return static::class;
	}

	protected function getTemplateDirectoryByClass(string $controllerClass): string
	{
		$controllerPatterns = '#Controller\\\(?<class>.+)Controller$#';
		preg_match($controllerPatterns, $controllerClass, $matches);

		if (empty($matches['class'])) {
			throw new Exception('Invalid Controller Class.');
		}

		return rtrim(
			Container::underscore(str_replace('\\', '/', preg_replace('/Action$/i', '', $matches['class']))),
			'/'
		);
	}

	protected function getTemplate(string $template, string $fallbackTemplate = null): string
	{
		if (!$this->twig) {
			throw new Exception('Missing Twig Templating Engine.');
		}

		$templatePath = sprintf(
			'%s/%s.html.twig',
			$this->getTemplateDirectoryByClass($this->getControllerClass()),
			$template
		);

		if (!$this->twig->getLoader()->exists($templatePath)) {
			$templatePath = sprintf('@DakataaCrud/%s.html.twig', $fallbackTemplate ?: $template);
		}

		return $templatePath;
	}

	/**
	 * @throws Exception
	 */
	protected function response(Request $request, array $data, int $status = 200, string $defaultTemplate = null): Response
	{
		[, $template] = explode('::', $request->get('_controller'));

		$attributes = array_merge(...array_map(fn(Column $column) => explode('.', $column->getField()), iterator_to_array($this->getEntityColumns())));

		switch ($request->getPreferredFormat()) {
			case 'json': {
				$classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
				$serializer = new Serializer(
					[
						new FormErrorNormalizer(),
						new FormViewNormalizer(),
						new BackedEnumNormalizer(),
						new ColumnNormalizer(),
						new DateTimeNormalizer([
							DateTimeNormalizer::FORMAT_KEY => 'Y-m-d H:i:s',
						]),
						new ObjectNormalizer($classMetadataFactory, defaultContext: [
							AbstractNormalizer::GROUPS => ['view'],
//							AbstractNormalizer::ATTRIBUTES => $attributes
						]),
					]
				);

				return new JsonResponse(
					$serializer->normalize($data, context: [
						AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => fn() => null,
					]), $status
				);
				break;
			}
			default:
				return new Response(
					$this->twig->render($this->getTemplate($template, $defaultTemplate), $data),
					$status
				);
		}

	}

	protected function getBatchForm(Request $request): FormInterface
	{
		$form = $this
			->formFactory
			->createNamedBuilder('batch', options: [
				...($this->parameterBag->get('form.type_extension.csrf.enabled') ? ['csrf_protection' => false] : []),
			])
			->setMethod('POST');

		$form
			->add('ids', CollectionType::class, [
				'required' => true,
				'allow_add' => true,
				'constraints' => [
					new Assert\NotBlank(),
				],
			]);


		$classInstance = new ReflectionClass($this->getControllerClass());
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
			->setMethod(Request::METHOD_GET);

		foreach ($this->buildColumns(searchable: true) as $columnData) {
			[
				'fqcn' => $fqcn,
				'type' => $type,
				'column' => $column,
			] = $columnData;

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
			array_filter(iterator_to_array($this->buildColumns()), fn(array $c) => $c['column']->getSortable() !== false
			),
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

	private function buildColumns(EntityColumnViewTypeEnum $viewType = null, bool $searchable = false): Generator
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
				'canSelect' => $entityMetadata->hasField($fieldName) && false === $entityMetadata->hasAssociation(
						$fieldName
					),
			];
		};

		foreach ($this->getEntityColumns($viewType, $searchable) as $column) {
			if (false !== $columnData = $buildColumn($column->getField(), $column)) {
				yield $columnData;
			}

			if ((($searchableField = $column->getSearchable()) instanceof SearchableOptions)) {
				if ($searchableField->getField() && false !== $columnData = $buildColumn(
						$searchableField->getField(),
						new Column($searchableField->getField(), searchable: $searchableField, sortable: false)
					)) {
					yield $columnData;
				}
			}
		}
	}

	/**
	 * @throws Exception
	 */
	protected function buildQuery(
		Request $request,
		QueryBuilder $query,
		EntityColumnViewTypeEnum $viewType = EntityColumnViewTypeEnum::List
	): self {
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

		$filters = array_filter($this->getFilters($request), fn(mixed $value) => $value !== null && $value !== '');

		foreach (
			$this->buildColumns($viewType) as ['entityAlias' => $entityAlias,
			'entityField' => $entityField,
			'assotiations' => $assotiations,
			'type' => $type,
			'column' => $column,
			'canSelect' => $canSelect,]
		) {
			$hasFilterApplied = isset($filters[$column->getAlias()]) && false !== $column->getSearchable();

			if ($canSelect || $hasFilterApplied) {
				foreach ($assotiations as $assotiation) {
					$query->innerJoin($assotiation['entity'].'.'.$assotiation['field'], $assotiation['alias']);
				}
			}

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
								'DATE(%s.%s) = \'%s\'',
								$entityAlias,
								$entityField,
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
	 * @param EntityColumnViewTypeEnum|null $viewType
	 * @return Column[]
	 * @throws Exception
	 *
	 */
	public function getEntityColumns(EntityColumnViewTypeEnum $viewType = null, bool $searchable = false): Generator
	{
		if (empty($this->entity->columns)) {
			$columns = [];
			foreach (
				$this->entityManager->getClassMetadata($this->getEntity()->getFqcn())->getFieldNames() as $fieldName
			) {
				if ($entityColumn = $this->getEntityFieldMetadata($fieldName)) {
					$columns[] = new Column($fieldName);
				}
			}

			$this->entity->columns = $columns;
		}

		foreach (
			array_filter(
				$this->entity->columns,
				fn(Column $c) => (
						null === $c->getViewType() ||
						$c->getViewType() === $viewType
					)
					&&
					(
						null === $c->getSearchable() ||
						$c->getSearchable() instanceof SearchableOptions ||
						$searchable === $c->getSearchable()
					)
			) as $column
		) {
			yield $column;
		}
	}

	public function getEntityClassMetadata(): ClassMetadata
	{
		if (!$this->entityClassMetadata) {
			$this->entityClassMetadata = $this->entityManager->getClassMetadata($this->getEntity()->getFqcn());
		}

		return $this->entityClassMetadata;
	}

	/**
	 * @param $field
	 * @throws Exception
	 */
	public function getEntityFieldMetadata(string $field): ?array
	{
		if (!$this->getEntityClassMetadata()->hasField(lcfirst($field))) {
			return null;
		}

		return $this->getEntityClassMetadata()->getFieldMapping(lcfirst($field));
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
		$split = explode('\\', $this->getControllerClass());
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


	protected function getMappedRoutes(): array
	{
		return [];
	}

	protected function hasRoute(string $method): bool
	{
		return method_exists($this, $method);
	}

	protected function getRoute(string $method = null): string
	{
		$mappedRoute = $this->getMappedRoutes()[$method] ?? null;
		if (null !== $mappedRoute) {
			return $mappedRoute;
		}

		if (!method_exists($this, $method)) {
			throw new NotFoundHttpException(sprintf('Missing Route "%s"', $method));
		}

		return $this::class.'::'.$method;
	}
}

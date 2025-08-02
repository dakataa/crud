<?php

namespace Dakataa\Crud\Controller;

use BackedEnum;
use Closure;
use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Column;
use Dakataa\Crud\Attribute\Entity;
use Dakataa\Crud\Attribute\EntityGroup;
use Dakataa\Crud\Attribute\EntityJoinColumn;
use Dakataa\Crud\Attribute\EntitySort;
use Dakataa\Crud\Attribute\EntityType;
use Dakataa\Crud\Attribute\Enum\ActionVisibilityEnum;
use Dakataa\Crud\Attribute\Enum\EntityColumnViewGroupEnum;
use Dakataa\Crud\Attribute\PathParameterToFieldMap;
use Dakataa\Crud\Attribute\SearchableOptions;
use Dakataa\Crud\Serializer\Normalizer\ActionNormalizer;
use Dakataa\Crud\Serializer\Normalizer\ColumnNormalizer;
use Dakataa\Crud\Serializer\Normalizer\FormErrorNormalizer;
use Dakataa\Crud\Serializer\Normalizer\FormViewNormalizer;
use Dakataa\Crud\Serializer\Normalizer\RouteNormalizer;
use Dakataa\Crud\Twig\TemplateProvider;
use Dakataa\Crud\Utils\Doctrine\Paginator;
use Dakataa\Crud\Utils\StringHelper;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\Types\Types;
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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;
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

	const RESULTS_LIMIT_DEFAULT = 5;
	const RESULTS_LIMIT_MAX = 100;

	const COMPOSITE_IDENTIFIER_SEPARATOR = '-';

	protected ?Entity $entity = null;
	protected ?array $entityType = null;
	protected ?ClassMetadata $entityClassMetadata = null;
	protected ?array $actions = null;

	protected array $forms = [];

	private ?ExpressionLanguage $expressionLanguage = null;


	private array $reflectionCache = [];

	protected CrudServiceContainer $serviceContainer;

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

	#[Required]
	public function setServiceContainer(CrudServiceContainer $loader): void {
		$this->serviceContainer = $loader;

		$this->entity = $this->getPHPAttribute(Entity::class);

		if (empty($this->entity?->joins)) {
			$this->entity?->setJoins($this->getPHPAttributes(EntityJoinColumn::class));
		}

		if (empty($this->entity?->group)) {
			$this->entity?->setGroup($this->getPHPAttributes(EntityGroup::class));
		}

		if (empty($this->entity?->sort)) {
			$this->entity?->setSort($this->getPHPAttributes(EntitySort::class));
		}

		if (empty($this->entity?->columns)) {
			$this->entity?->setColumns($this->getPHPAttributes(Column::class));
		}
	}

	final public function getEntity(): Entity
	{
		if (!$this->entity) {
			throw new Exception(
				'Invalid CRUD Entity Class. Add PHP Attribute "Dakataa\Crud\Attribute\Entity" or extend getEntity method.'
			);
		}

		return $this->entity;
	}

	public function getEntityType(Action $action = null): ?EntityType
	{
		if (!$this->entityType) {
			$this->entityType = $this->getPHPAttributes(EntityType::class, $action->name) ?: $this->getPHPAttributes(EntityType::class);
		}

		return current(
			array_values(
				array_filter(
					$this->entityType,
					fn(EntityType $t) => in_array($t->action, [$action?->name, null])
				)
			)
		) ?: null;
	}

	protected function compileEntityData(
		Request $request,
		array|object $object,
		EntityColumnViewGroupEnum|string $viewGroup = null,
		bool $raw = true
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
		foreach ($this->getEntityColumns($viewGroup, includeIdentifier: true) as $column) {
			$fieldAlias = $column->getAlias();

			$value = $this->columnValueDetermination($request, $object, $column);

			if(false === $value) {
				$value = null;
				foreach (['get', 'has', 'is'] as $methodPrefix) {
					$method = sprintf('%s%s', $methodPrefix, Container::camelize(Container::underscore($fieldAlias)));
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

				if (null === $value && isset($additionalEntityFields[$fieldAlias])) {
					$value = $additionalEntityFields[$fieldAlias];
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

//			if(!$raw) {
			if (is_object($value)) {
				if ($value instanceof Stringable) {
					$value = $value->__toString();
				} else {
					$value = json_encode($value);
				}
			}
//			}

			try {
				$enum = $column->getEnum() ?: [];
				$value = $enum[$value] ?? $value;
			} catch (TypeError $e) {
			}

			$result[$column->getField()] = $value;
		}

		return $result;
	}

	protected function columnValueDetermination(Request $request, object $object, Column $column): false|null|string|int|float|BackedEnum
	{
		return false;
	}

	protected function prepareListData(Request $request, Paginator $paginator, EntityColumnViewGroupEnum|string $viewGroup = null): array
	{
		['items' => $items, 'meta' => $meta] = $paginator->paginate();

		return [
			'items' => array_map(fn(array|object $object) => $this->compileEntityData($request, $object, $viewGroup), iterator_to_array($items)),
			'meta' => $meta,
		];
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
		$action = $this->getAction('list');
		if(empty($action))
			throw new Exception('This Action "list" is not enabled in the list of Entity Actions.');

//		['pagination' => $pagination] = (new OptionsResolver)
//			->setDefined(['filter'])
//			->setAllowedTypes('filter', 'boolean')
//			->setDefaults([
//				'filter' => true,
//			])->resolve($action->options ?? []);

		$filterForm = $this->getFilterForm($request);
		$batchForm = $this->handleBatch($request);
		if ($batchForm instanceof Response) {
			return $batchForm;
		}

		$sorting = $this->prepareSorting($request);
		$paginator = new Paginator(
			$this->createQueryBuilder($request),
			$request->query->getInt('page', 1),
			$this->getEntity()->isPagination() && (count($this->getEntityClassMetadata()->getIdentifierFieldNames()) === 1) ? $this->prepareMaxResults($request) : null
		);

		return $this->response($request, [
			'title' => $action?->title ?: StringHelper::titlize($this->getEntityShortName()),
			'entity' => [
				'name' => $this->getEntityShortName(),
				'primaryColumn' => $this->getEntityPrimaryColumn(),
				'columns' => iterator_to_array($this->getEntityColumns()),
				'data' => $this->prepareListData($request, $paginator),
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
			'action' => $this->getActions(),
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
		$action = $this->getPHPAttribute(Action::class, 'export');
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
			$rows[] = $this->compileEntityData($request, $object, EntityColumnViewGroupEnum::Export);
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
		return $this->modify($request, $this->getAction('add'), save: $save ?: true);
	}

	/**
	 * @throws Exception
	 */
	#[Route(path: '/{id}/view')]
	#[Action(visibility: ActionVisibilityEnum::Object)]
	public function view(Request $request, int|string $id): ?Response
	{
		$action = $this->getPHPAttribute(Action::class, 'view');
		$object = $this->getEntityRepository()->find($this->getEntityIdentifierPrepare($id));
		if (empty($object)) {
			throw new NotFoundHttpException('Not Found');
		}

		return $this->response($request, [
			'title' => $action?->title,
			'object' => $object,
			'data' => $this->compileEntityData($request, $object),
			'columns' => $this->getEntityColumns(EntityColumnViewGroupEnum::View),
		], defaultTemplate: 'view');
	}

	final protected function modify(Request $request, Action $action = null, mixed $id = null, bool $save = true): ?Response
	{
		if(empty($action))
			throw new Exception('This Action is not enabled in the list of Entity Actions.');

		if (!$this->getEntityType($action)) {
			throw new NotFoundHttpException('Not Entity Type found.');
		}

		$messages = [];

		if ($id) {
			$object = $this->getEntityRepository()->find($this->getEntityIdentifierPrepare($id));
			if (empty($object)) {
				throw new NotFoundHttpException('Not Found');
			}
		} else if(false !== $object = $this->findEntityObjectByRequest($request, $action)) {
			if(!is_a($object, $this->getEntity()->getFqcn(), true)) {
				throw new NotFoundHttpException('Not Found');
			}
		} else {
			$object = new ($this->getEntityClassMetadata()->getName());

			/** @var PathParameterToFieldMap[] $mappedPathParameters */
			$mappedPathParameters = [
				...$this->getPHPAttributes(PathParameterToFieldMap::class),
				...$this->getPHPAttributes(PathParameterToFieldMap::class, $action->name)
			];

			foreach ($mappedPathParameters as $mappedPathParameter) {
				$fieldName = $mappedPathParameter->getField();
				$column = $this->buildColumn(new Column($fieldName));
				if (!$column) {
					throw new Exception(sprintf('Invalid field mapping for %s', $fieldName));
				}

				if($this->getEntity()->getFqcn() !== $column['fqcn']) {
					continue;
				}

				$columnName = $column['entityField'];
				$fieldValue = $request->get($mappedPathParameter->getPathParameter());

				if ($this->getEntityClassMetadata()->hasAssociation($columnName)) {
					$associationClassName = $this->getEntityClassMetadata()->getAssociationTargetClass($columnName);
					if (null === $fieldValue = $this->serviceContainer->entityManager->getRepository($associationClassName)->find($fieldValue)) {
						throw new Exception(
							sprintf('Cannot found "%s" association with PK %s', $columnName, $fieldValue)
						);
					}
				}

				$this->getEntityClassMetadata()->setFieldValue($object, $columnName, $fieldValue);
			}
		}

		// Setup Form type options
		$formOptions = array_merge_recursive([
			'action' => $request->getUri(),
			'method' => Request::METHOD_POST,
			'csrf_protection' => false,
		], $this->getEntityType($action)?->getOptions() ?: []);

		$this->onFormTypeBeforeCreate($request, $object, $action);
		$form = $this->serviceContainer->formFactory->create(
			$this->getEntityType($action)?->getFqcn(),
			$object,
			$formOptions
		);

		$this->onFormTypeCreate($request, $form, $object);
		$responseStatus = 200;
		if ($request->isMethod(Request::METHOD_POST)) {
			$form->handleRequest($request);

			// Allow submitting empty forms
			if(false === $form->isSubmitted()) {
				$form->submit([]);
			}

			if ($form->isSubmitted() && $form->isValid() && $save) {
				$this->beforeFormSave($request, $form);

				$this->serviceContainer->entityManager->persist($form->getData());
				$this->serviceContainer->entityManager->flush();

				$this->afterFormSave($request, $form);

				$messages = [
					'success' => [
						$this->getEntityType()?->getSuccessMessage() ?: 'Item was saved successfully',
					],
				];

				$action = $this->getAction('edit') ?: $this->getAction('list');

				if ($action) {
					if ($action->getRoute()) {
						$route = $this->serviceContainer->router->getRouteCollection()->get($action->getRoute()->getName());
						$routeVariables = $route->compile()->getPathVariables();

						$redirect = [
							'route' => $this->serviceContainer->router->getRouteCollection()->get($action->getRoute()->getName()),
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
				}
			} else {
				$responseStatus = 400;
			}
		}

		return $this->response($request, [
			'title' => $action?->title ?: ($id ? 'Edit' : 'New'),
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
		return $this->modify($request, $this->getAction('edit'), $id, $save ?: true);
	}

	#[Route(path: '/{id}/delete', methods: ['DELETE', 'OPTIONS'])]
	#[Action(visibility: ActionVisibilityEnum::Object)]
	public function delete(Request $request, int|string $id): Response
	{
		if ($request->isMethod(Request::METHOD_OPTIONS)) {
			return new Response;
		}

		$object = $this->getEntityRepository()->find($this->getEntityIdentifierPrepare($id));
		if ($object) {
			$this->batchDelete($request, [$object]);
		}

		$route = $this->serviceContainer->router->getRouteCollection()->get($this->getRoute('list')->getName());
		$routeVariables = $route->compile()->getPathVariables();

		return new RedirectResponse($this->serviceContainer->router->generate($this->getRoute('list')->getName(), [
			...array_intersect_key($request->attributes->all(), array_flip($routeVariables)),
		]));
	}

	protected function handleBatch(Request $request): Response|FormInterface|null
	{
		if (!$this->getEntity()->batch) {
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
				$classMetadataFactory = new ClassMetadataFactory(new AttributeLoader);
				$serializer = new Serializer(
					[
						new FormErrorNormalizer,
						new FormViewNormalizer,
						new BackedEnumNormalizer,
						new ColumnNormalizer,
						new ActionNormalizer($this->serviceContainer->router),
						new DateTimeNormalizer([
							DateTimeNormalizer::FORMAT_KEY => 'Y-m-d H:i:s',
						]),
						new RouteNormalizer,
//						new ObjectNormalizer($classMetadataFactory, defaultContext: [
//							AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => fn() => null,
//							AbstractNormalizer::GROUPS => ['view'],
//						]),
					]
				);

				return new JsonResponse(
					$serializer->normalize($data, context: [
						AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => fn() => null,
					]), $status
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
				...($this->serviceContainer->parameterBag->get('form.type_extension.csrf.enabled') ? ['csrf_protection' => false] : []),
			]);

		$form->setMethod('POST');

		$form
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
		if (!$this->getEntity()->filter) {
			return null;
		}

		if (empty($this->forms['filter'])) {
			$form = $this->serviceContainer->formFactory->createNamedBuilder(
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
						$form->add($formFieldKey, DateType::class, [
							'placeholder' => '',
							...$columnOptions,
						]);
						break;
					case Types::BOOLEAN:
						$form->add($formFieldKey, ChoiceType::class, [
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

			$this->forms['filter'] = $form->getForm()->handleRequest($request);
		}

		return $this->forms['filter'];
	}

	protected function getDefaultSort(): ?array
	{
		return array_reduce($this->getEntity()->sort ?: [], fn(array $result, EntitySort $sort) => [
			...$result,
			$sort->field => $sort->sort->value,
		], []);
	}

	protected function prepareSorting(
		Request $request = null,
		EntityColumnViewGroupEnum|string $viewGroup = null
	): array {
		$sorting = $request->query->all('sort') ?: array_filter(
			$request->getSession()->get(
				$this->getAlias().'.sort',
				[]
			)
		) ?: $this->getDefaultSort();

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
		$limit = ($request->hasSession() ? intval($request->getSession()->get($this->getAlias().'.limit')) : null) ?: self::RESULTS_LIMIT_DEFAULT;
		$limit = min(
			self::RESULTS_LIMIT_MAX,
			max(
				self::RESULTS_LIMIT_DEFAULT,
				round(
					$request->query->getInt('limit', $limit) / self::RESULTS_LIMIT_DEFAULT
				) * self::RESULTS_LIMIT_DEFAULT
			)
		);

		if ($request->hasSession()) {
			$request->getSession()->set($this->getAlias().'.limit', $limit);
		}

		return $limit;
	}

	protected function buildCustomQuery(Request $request, QueryBuilder $query): self
	{
		return $this;
	}

	private function buildColumn(Column $column): array|false {
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

				$entityMetadata = $this->serviceContainer->entityManager->getClassMetadata($associationMapping['targetEntity']);
			}
		} else {
			if (
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
		bool $searchable = false,
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
		$entity = $this->getEntity();
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
		$columnFieldsMapping = $this->getEntityColumnToFieldMapping();

		/** @var PathParameterToFieldMap[] $mappedPathParameters */
		$mappedPathParameters = $this->getPHPAttributes(PathParameterToFieldMap::class);
		$urlPathParameters = array_intersect_key(
			$request->attributes->all(),
			array_flip(array_filter($request->attributes->keys(), fn(string $key) => !str_starts_with($key, '_')))
		);
		foreach ($mappedPathParameters as $mappedPathAttribute) {
			if (!isset($urlPathParameters[$mappedPathAttribute->getPathParameter()])) {
				throw new Exception(
					sprintf('Missing mapped path attribute: %s', $mappedPathAttribute->getPathParameter())
				);
			}

			$column = $this->buildColumn(new Column($mappedPathAttribute->getField()));
			if (!$column) {
				throw new Exception(sprintf('Missing column for field: %s', $mappedPathAttribute->getField()));
			}

			$pathParameter = $mappedPathAttribute->getPathParameter();
			$pathParameterValue = $urlPathParameters[$pathParameter];
			$queryParameterAlias = sprintf('pp%s', Container::camelize($mappedPathAttribute->getField()));
			$query->andWhere(
				sprintf(
					'%s.%s = :%s',
					$column['entityAlias'],
					$column['entityField'],
					$queryParameterAlias
				)
			)->setParameter($queryParameterAlias, $pathParameterValue);
		}

		$relationExpressions = [];
		foreach (
			$this->buildColumns($viewGroup, true, true) as [
			'entityAlias' => $entityAlias,
			'entityField' => $entityField,
			'relations' => $relations,
			'type' => $type,
			'column' => $column,
			'canSelect' => $canSelect,
			'nullable' => $nullable,
		]) {
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

					$query->leftJoin($relationExpression, $relation['alias']);
					$relationExpressions[] = $relationExpression;
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

			if ($isFilterApplied) {
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
			if($this->getEntityClassMetadata()->hasField($field)) {
				$queryEntityField = sprintf('%s.%s', self::ENTITY_ROOT_ALIAS, $field);
				if ($this->getEntityClassMetadata()->isNullable($field)) {
					// Move NULL values at the end
					$query->addOrderBy(sprintf('CASE WHEN %s is null THEN 1 ELSE 0 END', $queryEntityField));
				}

				$query
					->addOrderBy($queryEntityField, $direction);
			}
		}

		$this->buildCustomQuery($request, $query);

		return $query;
	}

	public function getEntityIdentifierValueFromObject(mixed $object): string
	{
		return implode(
			self::COMPOSITE_IDENTIFIER_SEPARATOR,
			$this->getEntityClassMetadata()->getIdentifierValues($object)
		);
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
	 * @return Generator
	 * @throws Exception
	 */
	public function getEntityColumns(
		EntityColumnViewGroupEnum|string|false $viewGroup = null,
		bool $searchable = false,
		bool $includeIdentifier = false
	): Generator {
		$viewGroup = (is_string($viewGroup) ? EntityColumnViewGroupEnum::tryFrom($viewGroup) : null) ?: $viewGroup;

		if (empty($this->getEntity()->columns)) {
			$this->getEntity()->columns = array_map(
				fn(string $fieldName) => new Column($fieldName),
				$this->getEntityClassMetadata()->getFieldNames()
			);
		}

		$columns = array_filter(
			$this->getEntity()->columns,
			fn(Column $c) => (
					!$c->getGroup() ||
					$c->getGroup() === $viewGroup
				)
				&&
				(
					null === $c->getSearchable() ||
					$c->getSearchable() instanceof SearchableOptions ||
					$searchable === $c->getSearchable()
				)
		);

		if ($includeIdentifier) {
			$availableColumnFields = [];
			$primaryEntityColumn = $this->getEntityPrimaryColumn();
			foreach ($columns as $column) {
				$column->setIdentifier(in_array($column->getField(), [$primaryEntityColumn->getField()]));
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
			$this->entityClassMetadata = $this->serviceContainer->entityManager->getClassMetadata($this->getEntity()->getFqcn());
		}

		return $this->entityClassMetadata;
	}

	public function getEntityColumnToFieldMapping(): array
	{
		return array_merge(
			array_combine(
				$this->getEntityClassMetadata()->getColumnNames(),
				$this->getEntityClassMetadata()->getFieldNames()
			),
			array_reduce(
				$this->getEntityClassMetadata()->getAssociationNames(),
				function (array $result, $associationName) {
					$association = $this->getEntityClassMetadata()->getAssociationMapping($associationName);
					foreach ($association['joinColumns'] ?? [] as $joinColumn) {
						$result[$joinColumn['name']] = $associationName;
					}

					return $result;
				},
				[]
			)
		);
	}

	/**
	 * @throws Exception
	 */
	public function getEntityShortName(): string
	{
		try {
			$classInstance = $this->getReflectionClass($this->getEntity()->getFqcn());
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
		return $this->serviceContainer->entityManager->getRepository($this->getEntity()->getFqcn());
	}

	protected function getRoute(string $method = null): Route
	{
		if (null !== $action = current(array_values(array_filter($this->getActions(), fn(Action $action) => $action->getName() === $method))) ?: null) {
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

	public function getActions(): array
	{
		return array_filter(
			$this->actions ?: $this->actions = array_filter(
				iterator_to_array(
					$this->serviceContainer->actionCollection->load(
						$this->getControllerClass(),
						$this->getEntity()->getFqcn()
					)
				),
				fn(Action $action) => null === $this->getEntity()->getActions() || in_array($action->getName(), $this->getEntity()->getActions())
			),
			[$this, 'isActionVisible']
		);
	}

	public function getAction(string $name): ?Action
	{
		return current(
			array_values(array_filter($this->getActions(), fn(Action $action) => $action->getName() === $name))
		) ?: null;
	}

	/**
	 * Helper method to determine action visibility
	 *
	 * @param Action $action
	 * @return bool
	 */
	public function isActionVisible(Action $action): bool
	{
		return true;
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

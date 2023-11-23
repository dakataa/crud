<?php

namespace Dakataa\Crud\Controller;

use Dakataa\Crud\Utils\Doctrine\Paginator;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use Locale;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Html;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Container;
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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class AbstractCrudController implements CrudControllerInterface
{
	const ENTITY_TABLE_ALIAS = 'a';

	const MODE_DISPLAY = 'display';
	const MODE_EXPORT = 'export';

	const EXPORT_EXCEL = 'excel';
	const EXPORT_EXCEL2007 = 'excel2007';
	const EXPORT_CSV = 'csv';
	const EXPORT_HTML = 'html';

	const DEFAULT_RESULTS_LIMIT = 20;

	public function __construct(
		protected FormFactoryInterface $formFactory,
		protected RouterInterface $router,
		protected EventDispatcherInterface $dispatcher,
		protected EntityManagerInterface $entityManager,
	) {
	}

	public final function redirectToRoute(
		string $route,
		array $parameters = [],
		int $status = 302
	): RedirectResponse {
		return new RedirectResponse($this->router->generate($route, $parameters, $status));
	}

	/**
	 * @throws Exception
	 */
	#[Route(path: '')]
	public function index(Request $request): Response
	{
		$this->setResultsLimit($request);

		//Sort list by column
		if ($sortColumn = $request->query->get('sort')) {
			if ($this->getModelColumn($sortColumn)) {
				$sortColumnType = $request->query->get('sort_type', 'asc');
				$this->setSort($request, [$sortColumn, $sortColumnType]);
			}
		}
		$resultsLimit = $this->getResultsLimit($request);
		$query = $this->getEntityRepository()->createQueryBuilder('a');
		$this
			->buildQuery($request, $query)
			->buildCustomQuery($request, $query);

		$query
			->setMaxResults($resultsLimit);

		$paginator = new Paginator($query, $request->query->getInt('page', 1));

		return $this->response('index', [
			'objects' => $paginator,
			'filter' => $this->getFilterForm($request)->createView(),
			'hasFilters' => $this->hasFilters($request),
			'batch' => $this->getBatchForm($request)->createView(),
			'columns' => array_filter($this->getColumns(), fn(array $data) => empty($data['searchable'])),
			'objectColumns' => $this->getColumns(true),
			'title' => $this->getTitle(),
			'sort' => $this->getSort($request),
			'actions' => $this->getActions(),
			'objectActions' => $this->getObjectActions(),
			'batchActions' => $this->getBatchActions(),
			'modelName' => $this->getEntityShortName(),
			'entityClass' => $this->getEntityClass(),
			'controllerClass' => $this::class,
			'resultsLimit' => $resultsLimit,
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
		$columnOptionResolver = (new OptionsResolver())
			->setDefaults([
				'label' => '',
				'field' => null,
				'objectGetter' => null,
				'enum' => null,
				'dateFormat' => null,
				'emptyValue' => null,
			])
			->setAllowedTypes('label', ['string', 'null'])
			->setAllowedTypes('field', ['string', 'null'])
			// Getter when field value is Object
			->setAllowedTypes('objectGetter', ['string', 'null'])
			// Predefined values label formatter
			->setAllowedTypes('enum', ['array', 'null'])
			// Date Format when value is DateTime object
			->setAllowedTypes('dateFormat', ['string', 'null'])
			// Empty default value
			->setAllowedTypes('emptyValue', ['string', 'null']);
		//Excel
		$spreadsheet = new Spreadsheet();
		$spreadsheet
			->getProperties()
			->setCreator(sprintf('Auto generated: %s', $this->getUser()))
			->setTitle('Export')
			->setCompany('Company');
		//Header
		$header = [];
		foreach ($columns as $columnField => $columnOption) {
			$columnName = $columnOption['label'] ?? ucfirst(str_replace('_', ' ', Container::underscore($columnField)));
			array_push($header, $translator->trans($columnName));

			// Check column options
			$columnOptionResolver->resolve($columnOption);
		}
		$rows = [$header];
		//Rows
		foreach ($objects as $object) {
			$customObjectFields = [];

			if (is_array($object)) {
				if ($object[0]::class === $this->getEntityClass()) {
					$customObjectFields = array_filter($object, fn($key) => !is_numeric($key), ARRAY_FILTER_USE_KEY);
					$object = $object[0];
				} else {
					throw new Exception('Invalid results.');
				}
			}

			$row = [];
			foreach ($columns as $columnField => $columnOption) {
				$field = $columnOption['field'] ?? $columnField;

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

				$enum = $columnOption['enum'] ?? [];
				$emptyValue = $columnOption['empty'] ?? null;

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

				if (is_string($value)) {
					$value = sprintf('%s ', $value);
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
	protected function response(string $name, array $parameters = []): Response
	{
		$className = $this::class;
		$controllerPatterns = '#Controller\\\(?<class>.+)Controller$#';
		preg_match($controllerPatterns, $className, $matches);

		if (empty($matches['class'])) {
			throw new Exception('Invalid Controller Class.');
		}

		$path = rtrim(
			Container::underscore(str_replace('\\', '/', preg_replace('/Action$/i', '', $matches['class']))),
			'/'
		);

		return $this->render(sprintf('%s/%s.html.twig', $path, $name), $parameters);
	}

	/**
	 *
	 * @param int|null $id
	 * @throws Exception
	 */
	#[Route(path: '/{id}/edit', requirements: ['id' => '\d+'])]
	public function edit(Request $request, int $id = null): ?Response
	{
		if (empty($this->getEntityTypeClass())) {
			throw $this->createNotFoundException('Not found');
		}
		$object = null;
		if ($id) {
			$metadata = $this->entityManager->getClassMetadata($this->getEntityClass());

			if (count($metadata->getIdentifierFieldNames()) > 1) {
				throw new Exception('Entity with two or more identifier columns are not supported.');
			}

			$identifierColumn = $metadata->getSingleIdentifierFieldName();

			$queryBuilder = $this
				->getEntityRepository()
				->createQueryBuilder(self::ENTITY_TABLE_ALIAS)
				->where(sprintf('%s.%s = :id', self::ENTITY_TABLE_ALIAS, $identifierColumn))
				->setParameter('id', $id);

			$object = $queryBuilder->getQuery()->getOneOrNullResult();

			if (empty($object)) {
				throw $this->createNotFoundException();
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
		$formOptions = array_merge_recursive($formOptions, $this->getCustomFormOptions());
		//PERMISSION
		if (empty($object)) {
			$entityClass = $this->getEntityClass();
			$object = new $entityClass();
		} else {
			$this->breadcrumb->add((new BreadcrumbItem)->setName($object ?? ''));
		}
		$this->onFormTypeBeforeCreate($request, $object);
		$form = $this->formFactory->createNamed(
			'form_'.Container::underscore($this->getEntityShortName()).'_'.($id ?? 'new'),
			$this->getEntityTypeClass(),
			$object,
			$formOptions
		);
		$this->onFormTypeCreate($request, $form, $object);
		if ($request->isMethod(Request::METHOD_POST)) {
			$response = $this->formValidator->validate($form);
			if ($form->isSubmitted() && $form->isValid()) {
				$this->beforeFormSave($request, $form);

				$this->entityManager->persist($form->getData());
				$this->entityManager->flush();
				$this->afterFormSave($request, $form);
				$this->addFlash('notice', 'Item was saved successfully.');
				if ($response) {
					return $response;
				}

				$object = $form->getData();

				return new RedirectResponse($this->redirectUrlAfterEdit($object));
			}
		}

		return $this->response('edit', [
			'object' => $object,
			'form' => $form->createView(),
		]);
	}


	/**
	 * @param int|null $id
	 */
	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'], defaults: ['id' => 'null'])]
	public function delete(Request $request, int $id = null): Response
	{
		$object = $this->getEntityRepository()->find($id);
		if ($object) {
			$this->batchDelete($request, [$object]);
		}

		return new RedirectResponse($this->router->generate($this->getRoute('index'), $request->query->all()));
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

		return new RedirectResponse($this->router->generate($this->getRoute('index'), $request->query->all()));
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

	public function getBatchForm(Request $request): FormInterface
	{
		$form = $this
			->formFactory
			->createNamedBuilder('batch', options: [
				...($this->getParameter('form.type_extension.csrf.enabled') ? ['csrf_protection' => false] : []),
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

		//methods
		$methods = [];
		/** @var array $batchActions */
		$batchActions = $this->getBatchActions();

		foreach ($batchActions as $method => $options) {
			$action = $options['action'] ?? $method;
			$label = $options['label'] ?? ucfirst($method);

			$method = sprintf('batch%s', Container::camelize(Container::underscore($action)));

			if (!method_exists($this, $method) || !$this->secureControllerManager->hasPermission(
					$this::class,
					$method
				)) {
				continue;
			}

			$methods[$label] = $action;
		}

		if (!empty($methods)) {
			$form->add(
				'method',
				ChoiceType::class,
				['choices' => $methods, 'placeholder' => '', 'constraints' => [new Assert\NotBlank()]]
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
		$form = $this->getFilterForm($request);
		$form->submit($request->get('filter', []));

		$this->setFilters($request, $form->isValid() ? $form->getData() : []);

		return new RedirectResponse($this->router->generate($this->getRoute('index')));
	}

	/**
	 * @throws Exception
	 */
	private function getFilterForm(Request $request): FormInterface
	{
		$form = $this->formFactory->createNamedBuilder(
			'filter',
			FormType::class,
			$this->getFilters($request),
			[
				...($this->getParameter('form.type_extension.csrf.enabled') ? ['csrf_protection' => false] : []),
			]
		);
		$form
			->setAction($this->router->generate($this->getRoute('filter')))
			->setMethod('GET');

		foreach ($this->getColumns() as $item => $field) {
			$modelColumn = $this->getModelColumn($item);

			if (isset($field['searchable']) && !$field['searchable']) {
				continue;
			}

			$columnOptions = $field['options'] ?? [];
			if (empty($columnOptions['label'])) {
				$columnOptions['label'] = $field['label'] ?? null;
			}

			$columnType = $field['type'] ?? ($modelColumn['type'] ?? TextType::class);

			switch ($columnType) {
				case Types::INTEGER:
				case Types::BIGINT:
				case Types::TEXT:
				case Types::STRING:
				case Types::DECIMAL:
				case Types::SMALLINT:
					$form->add($item, TextType::class, $columnOptions);
					break;
				case Types::DATE_MUTABLE:
				case Types::DATETIME_MUTABLE:
					$defaultOptions = ['placeholder' => ''];
					$columnOptions = array_merge($defaultOptions, $columnOptions);
					$form->add($item, DateType::class, $columnOptions);
					break;
				case Types::BOOLEAN:
					$form->add($item, CheckboxType::class, $columnOptions);
					break;
				default:
					if (empty($columnType) || !is_string($columnType)) {
						$columnType = null;
					}

					$form->add(
						$item,
						class_exists($columnType) && is_a(
							$columnType,
							FormTypeInterface::class,
							true
						) ? $columnType : TextType::class,
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

	protected function getSort(Request $request)
	{
		return array_filter($request->getSession()->get($this->getAlias().'.sort', $this->getDefaultSort() ?? []));
	}

	protected function setSort(Request $request, ?array $sort): self
	{
		if (!empty($sort)) {
			$request->getSession()->set($this->getAlias().'.sort', $sort);
		}

		return $this;
	}

	public function getResultsLimit(Request $request): int
	{
		return min(
			100,
			max(
				self::DEFAULT_RESULTS_LIMIT,
				intval($request->getSession()->get($this->getAlias().'.limit', self::DEFAULT_RESULTS_LIMIT))
			)
		);
	}

	public function setResultsLimit(Request $request): self
	{
		if ($request->query->has('limit')) {
			$limit = round(
					($request->query->getInt('limit', self::DEFAULT_RESULTS_LIMIT)) / self::DEFAULT_RESULTS_LIMIT
				) * self::DEFAULT_RESULTS_LIMIT;
			$request->getSession()->set($this->getAlias().'.limit', min(100, max(self::DEFAULT_RESULTS_LIMIT, $limit)));
		}

		return $this;
	}

	/**
	 * @throws Exception
	 */
	protected function processSort(Request $request, QueryBuilder $query)
	{
		$sort = $this->getSort($request);
		if (empty($sort)) {
			return;
		}

		list($field, $sorting) = $sort;

		if ($this->getModelColumn($field)) {
			$query->orderBy(
				sprintf('%s.%s', self::ENTITY_TABLE_ALIAS, $field),
				Criteria::ASC == $sorting ? Criteria::ASC : Criteria::DESC
			);
		}
	}

	protected function getFilters(Request $request): array
	{
		return array_filter($request->getSession()->get($this->getAlias().'.filters', []));
	}

	protected function setFilters(Request $request, array $filters): self
	{
		$request->getSession()->set($this->getAlias().'.filters', $filters);

		return $this;
	}

	protected function hasFilters(Request $request): bool
	{
		return !empty($this->getFilters($request));
	}

	protected function getFilterValue(Request $request, string $key): mixed
	{
		return $this->getFilters($request)[$key] ?? null;
	}


	protected function buildCustomQuery(Request $request, QueryBuilder $query): self
	{
		return $this;
	}

	/**
	 * @throws Exception
	 */
	protected function buildQuery(Request $request, QueryBuilder $query, string $mode = self::MODE_DISPLAY): self
	{
		//Joins
		foreach ($this->getEntityJoin() as $table => $join) {
			switch ($join['type'] ?? Join::LEFT_JOIN) {
				case Join::INNER_JOIN:
					$query->join($table, $join['alias'] ?? $table, Join::WITH, $join['condition'] ?? null);
					break;
				case Join::LEFT_JOIN:
				default:
					$query->leftJoin($table, $join['alias'] ?? $table, Join::WITH, $join['condition'] ?? null);
					break;
			}
		}

		//Groups
		foreach ($this->getEntityGroup() as $field) {
			$query->groupBy($field);
		}

		$columns = $this->getColumns(false, $mode);

		switch ($mode) {
			case self::MODE_EXPORT:
			{
				foreach ($columns as $key => $column) {
					if (isset($column['field']) && !empty($this->getModelColumn($column['field']))) {
						$query
							->addSelect(sprintf('%s as %s', $column['field'], $key));
					}
				}
				break;
			}
		}

		//Filter
		$counter = 1;
		foreach ($this->getFilters($request) as $filter => $value) {
			if ($value === null || $value === '') {
				continue;
			}

			$modelColumn = $this->getModelColumn($filter);

			if ($modelColumn === null
				&& isset($columns[$filter]['searchable'])
				&& !$columns[$filter]['searchable']) {
				continue;
			}

			$modelColumn = $this->getModelColumn($filter);

			if ($modelColumn === null && empty($columns[$filter]['field'])) {
				continue;
			}

			$modelColumn = array_merge_recursive($columns[$filter] ?? [], $modelColumn ?? []);
			$type = $modelColumn['type'] ?? Types::STRING;
			$parameter = sprintf('p%d', $counter);

			if (is_array($type)) {
				$type = array_pop($type);
			}

			$filterField = $modelColumn['field'] ?? sprintf('%s.%s', self::ENTITY_TABLE_ALIAS, $filter);

			switch ($type) {
				case Types::TEXT:
				case Types::STRING:
				case Types::SIMPLE_ARRAY:
				{
					$query
						->andWhere(
							sprintf(
								'%s LIKE :%s',
								$modelColumn['field'] ?? sprintf('%s.%s', self::ENTITY_TABLE_ALIAS, $filter),
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
						$query
							->andWhere(
								sprintf(
									'DATE(%s) = \'%s\'',
									sprintf('%s.%s', self::ENTITY_TABLE_ALIAS, $filter, $value->format('Y-m-d'))
								)
							);
					}

					// Start & End
					if (is_array($value)) {
						$value = array_filter($value);
						if (empty($value)) {
							break;
						}

						if (!empty($value['start']) && $value['start'] instanceof DateTime) {
							$value['start']->setTime(0, 0);
						}

						if (!empty($value['end']) && $value['end'] instanceof DateTime) {
							$value['end']->setTime(23, 59, 59);
						}

						if (isset($value['start']) && isset($value['end'])) {
							$query
								->andWhere(sprintf('%s BETWEEN :%s_start AND :%s_end', $filterField, $filter, $filter))
								->setParameter(sprintf('%s_start', $filter), $value['start'])
								->setParameter(sprintf('%s_end', $filter), $value['end']);
						} elseif (isset($value['start'])) {
							$query
								->andWhere(sprintf('%s >= :%s_start', $filterField, $filter))
								->setParameter(sprintf('%s_start', $filter), $value['start']);
						} elseif (isset($value['end'])) {
							$query
								->andWhere(sprintf('%s <= :%s_end', $filterField, $filter))
								->setParameter(sprintf('%s_end', $filter), $value['end']);
						}
					}
					break;
				}
				case Types::BOOLEAN:
				default:
				{
					$query
						->andWhere(sprintf("%s = :%s", $filterField, $parameter))
						->setParameter($parameter, $value);
					break;
				}
			}

			$counter++;
		}

		$this->processSort($request, $query);

		return $this;
	}

	/**
	 * @param string|null $mode
	 * @throws Exception
	 */
	public function getColumns(bool $all = false, string $mode = null): array
	{
		$fields = match ($mode) {
			self::MODE_EXPORT => $this->getExportFields(),
			default => $this->getDisplayFields(),
		};

		if (empty($fields) || $all) {
			foreach ($this->entityManager->getClassMetadata($this->getEntityClass())->getFieldNames() as $columnName) {
				$entityColumn = $this->getModelColumn($columnName);
				if (!empty($entityColumn)) {
					$fields[$columnName] = [
						'type' => $entityColumn['type'] ?? Types::TEXT,
						'label' => $columnName,
					];
				}
			}
		}

		return $fields;
	}

	/**
	 * @param $column
	 * @throws Exception
	 */
	public function getModelColumn($column): ?array
	{
		$entityClassMetaData = $this->entityManager->getClassMetadata($this->getEntityClass());
		if (!$entityClassMetaData->hasField($column)) {
			return null;
		}

		return $entityClassMetaData->getFieldMapping($column);
	}

	/**
	 * @throws Exception
	 */
	protected function getTitle(): string
	{
		return $this->getEntityShortName();
	}

	protected function getDisplayFields(): array
	{
		return [];
	}

	protected function getExportFields(): array
	{
		return [];
	}

	protected function getEntityJoin(): array
	{
		return [];
	}

	protected function getEntityGroup(): array
	{
		return [];
	}

	protected function getBundleConfig(): array
	{
		return [];
	}

	protected function getConfig(): array
	{
		$config = $this->getBundleConfig();

		return $config['generator'][$this->getAlias()] ?? [];
	}

	protected function getControllerParameter($path = null, $fallback = false)
	{
		if (!$path) {
			return null;
		}

		$config = $this->getConfig();
		$parameters = explode(".", $path);

		foreach ($parameters as $param) {
			if (isset($config[$param])) {
				$config = $config[$param];
				continue;
			}

			return $fallback;
		}

		return $config;
	}

	/**
	 * @throws Exception
	 */
	public function getEntityShortName(): string
	{
		try {
			$classInstance = new ReflectionClass($this->getEntityClass());
			$className = $classInstance->getShortName();
		} catch (ReflectionException) {
			throw new Exception(sprintf('Invalid or missing Entity Class definition: %s', $this->getEntityClass()));
		}

		return $className;
	}

	public function getAlias(): string
	{
		$controller_class = $this::class;
		$split = explode("\\", $controller_class);
		$controller_name = strtolower(str_replace("Controller", "", end($split)));

		return Container::underscore($controller_name);
	}


	protected function getLocale(): string
	{
		return Locale::getDefault();
	}

	protected function getCustomFormOptions(): array
	{
		return [];
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

	protected function redirectUrlAfterEdit($object): string
	{
		return $this->router->generate(
			$this->getRoute('edit'),
			['id' => method_exists($object, 'getId') ? $object->getId() : null]
		);
	}

	protected function getEntityRepository(): ObjectRepository
	{
		return $this->entityManager->getRepository($this->getEntityClass());
	}

	public function getActions(): array
	{
		return [
			'add' => [
				'label' => 'Add',
				'action' => 'add',
			],
		];
	}


	public function getObjectActions(): array
	{
		return [
			'edit' => [
				'label' => 'Edit',
				'action' => 'edit',
				'attr' => [
					'class' => 'btn-outline-primary',
				],
			],
			'delete' => [
				'label' => 'Remove',
				'action' => 'delete',
				'icon' => 'fa-trash',
				'confirm' => 'Are you sure?',
				'attr' => [
					'class' => 'btn-outline-danger',
				],
			],
		];
	}

	public function getBatchActions(): array
	{
		return [
			'delete' => [
				'label' => 'Remove',
				'action' => 'delete',
			],
		];
	}

	protected function getRoute(string $method = null): string
	{
		$name = strtolower(str_replace('\\', '_', $this::class).'_'.$method);

		return preg_replace([
			'/(bundle|controller)_/',
			'/action(_\d+)?$/',
			'/__/',
		], [
			'_',
			'\\1',
			'_',
		], $name);
	}
}

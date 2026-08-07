# CRUD Dashboard
Create fast and easy CRUD Dashboard.
This package itself works as a REST API and can be used
with ReactJS Package [@dakataa/crud-react](https://github.com/dakataa/crud-react), or you can enable **Twig** version by adding additional package [@dakataa/crud-twig](https://github.com/dakataa/crud-twig).

## How to Install
1. Setup Symfony Project.
	```shell
    symfony new --webapp crud
    ```
2. Create Entity And Form Type
	```shell
	php bin/console make:entity Product
	php bin/console make:form ProductType Product
 	...
	php bin/console make:migration
	php bin/console doctrine:migrations:migrate
    ```
3. Add package to composer
   ```shell
   composer require dakataa/crud
   ```

   add routes without recipe in config/routes/annotation.yaml:
	```yaml
	dakataa_crud:
		resource: '@DakataaCrudBundle/src/Controller'
		type: attribute
		prefix: /_crud
    ```
4. Allow controllers to inject services.
   Add this code to your services.yaml controllers are imported separately to make sure services can be injected
	```yaml
	App\Controller\:
		resource: '../src/Controller'
		tags: [ 'controller.service_arguments' ]
	```

5. Create first controller.
   Standard way:
   ```php
   namespace App\Controller;

   use App\Entity\Product;
   use App\Form\ProductType;
   use Dakataa\Crud\Attribute\Action;
   use Dakataa\Crud\Attribute\Entity;
   use Dakataa\Crud\Attribute\EntityType;
   use Dakataa\Crud\Controller\AbstractCrudController;
   use Doctrine\ORM\QueryBuilder;
   use Symfony\Component\HttpFoundation\Request;
   use Symfony\Component\Routing\Attribute\Route;
   
   #[Route('/product')]
   #[Entity(Product::class)]
   #[EntityType(ProductType::class)]
   class ProductController extends AbstractCrudController
   {
   }
   ```

   If you want to customize the initial query, add a `#[QueryResolver]` attribute to your controller (or to a specific action method). It is resolved before the query is executed and receives the current CRUD action.

   ```php
   use Dakataa\Crud\Attribute\Resolver\QueryResolver;
   use Dakataa\Crud\Controller\CrudServiceContainer;

   #[Route('/product')]
   #[Entity(Product::class)]
   #[EntityType(ProductType::class)]
   #[QueryResolver('buildCustomQuery')]
   class ProductController extends AbstractCrudController
   {
       protected function buildCustomQuery(Request $request, Action $action, QueryBuilder $query, CrudServiceContainer $serviceContainer): void
       {
           $query->andWhere('a.enabled = true');
       }
   }
   ```

   `resolver` accepts:
    - the name of a method on the controller (resolved via `getResolverContext()`, i.e. `$this` by default),
    - an invokable class (`SomeResolver::class`, instantiated with `new`, must implement `__invoke`),
    - any other PHP `callable` (e.g. a static method array or `Closure`).

   Restrict the resolver to specific actions with the `actions` argument, e.g. `#[QueryResolver('buildCustomQuery', actions: ['list', 'export'])]`. Without it, the resolver applies to every action. You can also put `#[QueryResolver]` directly on an action method — a method-level attribute takes precedence over the class-level one for that action.

    with Make Command

    ```shell
    php symfony crud:make:entity Product ProductType ProductController
    ```

## Action Discovery

The `/_crud/actions` endpoint returns CRUD action metadata for frontend clients such as `@dakataa/crud-react`.
The React client uses this metadata to build action URLs, labels, visibility rules, and permission-aware UI.

Action metadata can include permission information, but the frontend must treat it as discovery data only.
Every action endpoint must still enforce access on the server side when the action is executed.
Client-side AJAX checks are useful for hiding or disabling UI controls, but they are not a replacement for backend authorization.

## Entity Resolver

Use `#[EntityResolver]` when an action must load its entity with custom lookup logic instead of the default repository identifier lookup. The attribute can be placed on the controller class or directly on an action method.

```php
use App\Entity\Product;
use Dakataa\Crud\Attribute\Resolver\EntityResolver;
use Dakataa\Crud\Controller\AbstractCrudController;
use Dakataa\Crud\Controller\CrudServiceContainer;
use Symfony\Component\HttpFoundation\Request;

#[EntityResolver('findProduct', actions: ['view', 'edit'])]
class ProductController extends AbstractCrudController
{
    protected function findProduct(
        Request $request,
        CrudServiceContainer $serviceContainer
    ): ?Product {
        return $serviceContainer->entityManager
            ->getRepository(Product::class)
            ->findOneBy([
                'slug' => $request->attributes->get('id'),
            ]);
    }
}
```

An entity resolver can be limited to specific actions with `actions: [...]`. For the current action, a matching method-level resolver takes priority; when there is none, the matching class-level resolver is used. Like the other resolver attributes, `resolver` accepts a controller method name, an invokable class, or another PHP callable. The callable receives `Request` and `CrudServiceContainer` and must return an instance of the configured entity class or `null` when the entity cannot be found.

## Response Context Resolver

Use `#[ResponseContextResolver]` to add application-specific display data to a CRUD response without changing the built-in action workflow. The resolved data is added under the `context` key in both JSON responses and Twig template variables.

The resolver receives the request, current action, raw runtime context provided by the action, and the CRUD service container. It must return an array:

```php
use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Resolver\ResponseContextResolver;
use Dakataa\Crud\Controller\AbstractCrudController;
use Dakataa\Crud\Controller\CrudServiceContainer;
use Symfony\Component\HttpFoundation\Request;

#[ResponseContextResolver(
    resolver: 'resolveProductContext',
    actions: ['add', 'edit'],
)]
class ProductController extends AbstractCrudController
{
    protected function resolveProductContext(
        Request $request,
        Action $action,
        array $context,
        CrudServiceContainer $serviceContainer
    ): array {
        $product = $context['object'] ?? null;

        return [
            'helpText' => 'Changes are applied immediately.',
            'isEdit' => $action->getName() === 'edit',
            'productId' => $product?->getId(),
        ];
    }
}
```

The resulting JSON contains:

```json
{
  "context": {
    "helpText": "Changes are applied immediately.",
    "isEdit": true,
    "productId": 42
  }
}
```

The same values are available in Twig as `context.helpText` and `context.isEdit`.

The runtime context contains the original, non-normalized action data:

- `list`: `items` and pagination `meta`;
- `view`: `object`;
- `add` and `edit`: `object` and `form`.

Runtime context is available only to the resolver and is never included in the response automatically. Only the array returned by the resolver is exposed under the response's `context` key.

The attribute can also be placed directly on an action method:

```php
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/featured')]
#[Action]
#[ResponseContextResolver('resolveFeaturedContext')]
public function featured(Request $request): Response
{
    return parent::list($request);
}
```

`#[ResponseContextResolver]` is repeatable. Class-level resolvers run first, followed by method-level resolvers. Their results are combined with `array_replace_recursive()`, so a later resolver can override an earlier context value:

```php
#[ResponseContextResolver('resolveSharedContext')]
#[ResponseContextResolver('resolveListContext', actions: ['list'])]
class ProductController extends AbstractCrudController
{
}
```

Like the other resolver attributes, `resolver` accepts a method name on the controller, an invokable class, or another PHP callable. A resolver that returns a value other than an array causes an `UnexpectedValueException`.

The resolver runs for CRUD actions that produce a standard JSON or template response, including `list`, `view`, `add`, and `edit`. Redirect and streamed actions such as `delete` and `export` do not produce response context.

## Form Type Options Resolver

Use `#[FormTypeOptionsResolver]` to customize the options passed to the configured Symfony form type. The resolver receives the request, current action, current entity object, current options, and the CRUD service container, and must return the complete options array:

```php
use App\Entity\Product;
use Dakataa\Crud\Attribute\Action;
use Dakataa\Crud\Attribute\Resolver\FormTypeOptionsResolver;
use Dakataa\Crud\Controller\AbstractCrudController;
use Dakataa\Crud\Controller\CrudServiceContainer;
use Symfony\Component\HttpFoundation\Request;

#[FormTypeOptionsResolver(
    resolver: 'resolveFormTypeOptions',
    actions: ['add', 'edit'],
)]
class ProductController extends AbstractCrudController
{
    protected function resolveFormTypeOptions(
        Request $request,
        Action $action,
        ?Product $product,
        array $options,
        CrudServiceContainer $serviceContainer
    ): array {
        $options['validation_groups'] = $product?->getId() === null
            ? ['Default', 'create']
            : ['Default', 'update'];

        return $options;
    }
}
```

The attribute is repeatable and can be placed on the controller class or on an action method. Matching class-level resolvers run first, followed by matching method-level resolvers. Each resolver receives the options returned by the previous one, so later resolvers can add, replace, or remove options. As with the other resolver attributes, `resolver` accepts a controller method name, an invokable class, or another PHP callable. Returning a value other than an array causes an `UnexpectedValueException`.

## Column Value Resolver

For each column, `compileEntityData()` resolves the displayed value using the following priority chain, stopping at the first one that applies:

1. **`#[ColumnValueResolver]`** — if present and applicable to the column, its return value is used as-is (including `null` or `false`).
2. **Column getter** — if the column declares an explicit `getter` option, `get{Getter}()` is called on the entity.
3. **Query-selected field** — if the query added an extra selected field under the column's alias (e.g. via `addSelect('... AS someAlias')`), that value is used.
4. **Property fallback** — otherwise `get{Field}()`, `has{Field}()`, or `is{Field}()` is tried on the entity in that order.

By default (steps 2-4), you don't need to do anything — columns resolve themselves from the entity. Use `#[ColumnValueResolver]` when a column's value needs custom logic (formatting, computed values, cross-entity lookups, etc.):

```php
use Dakataa\Crud\Attribute\Resolver\ColumnValueResolver;
use Dakataa\Crud\Controller\CrudServiceContainer;

#[Route('/product')]
#[Entity(Product::class)]
#[EntityType(ProductType::class)]
#[ColumnValueResolver('resolveColumnValue')]
class ProductController extends AbstractCrudController
{
    protected function resolveColumnValue(Request $request, Product $product, Column $column, CrudServiceContainer $serviceContainer): mixed
    {
        return match ($column->getField()) {
            'stockStatus' => $product->getStock() > 0 ? 'In Stock' : 'Out of Stock',
            default => $product->{'get' . ucfirst($column->getField())}(),
        };
    }
}
```

Just like `#[QueryResolver]`, `resolver` accepts a method name on the controller, an invokable class, or any PHP `callable`, and can be scoped with `fields: [...]` (instead of `actions`) to only run for specific column fields:

```php
#[ColumnValueResolver('resolveStockStatus', fields: ['stockStatus'])]
```

It can also be placed on an action method to only apply to that action, falling back to the class-level resolver otherwise.

Once a value is resolved (by any of the four steps above), it goes through normalization before being sent to the client: `Collection`s are joined into a comma-separated string, `DateTimeInterface` values are formatted (`dateFormat` column option, default ATOM), `BackedEnum`s are reduced to their scalar value, and remaining arrays/objects are JSON-encoded unless the column is marked `raw`. Finally, if the column declares an `enum` map, the resolved value is looked up in it for display.

## How to extend templates

## Map URL parameter to entity column

## How to ...
